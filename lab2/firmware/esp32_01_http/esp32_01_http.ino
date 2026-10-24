#include <WiFi.h>
#include <WiFiUdp.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <cJSON.h>  // Componente JSON de ESP-IDF incluido en Arduino-ESP32 3.3.8.
#include <esp_system.h>
#include <esp_wifi.h>
#include <atomic>
#include <freertos/queue.h>
#include <time.h>
#include "TelemetryState.h"
#include "secrets.h"

namespace {
constexpr uint8_t PIR_PIN = 27;
constexpr uint8_t RELAY_PIN = 26;  // HIGH activa el transistor BC548 y el rele.
constexpr uint32_t HOLD_TIME_MS = 10000;
std::atomic<bool> pirMotion{false};
Preferences prefs;
e1::State state;
bool halted = false;
constexpr uint32_t SAMPLE_MS = 10000;
constexpr time_t MIN_TLS_TIME = 1767225600;  // 2026-01-01; no usar reloj de 1970 para TLS.
constexpr char SERVER_TLS_NAME[] = "telemetry.lab.test";
constexpr uint16_t SERVER_TLS_PORT = 8443;

// Solo communication() usa este estado. UDP anuncia una direccion candidata;
// HTTPS sigue autenticando telemetry.lab.test con CA, SAN y vigencia.
class ServerDiscovery {
  static constexpr uint16_t PORT = 37020;
  static constexpr uint32_t INTERVAL_MS = 3000;
  WiFiUDP udp;
  IPAddress local, mask, gateway, server;
  String bssid;
  bool listening = false, waiting = false;
  uint32_t lastTry = 0;
  char expected[64]{};
public:
  IPAddress address() const { return server; }
  bool ready() const { return static_cast<uint32_t>(server) != 0; }

  void invalidate() {
    udp.stop();
    listening = waiting = false;
    server = IPAddress();
    lastTry = millis();
  }

  void poll(bool online, uint32_t now) {
    if (!online) {
      if (listening || ready()) invalidate();
      local = IPAddress();
      return;
    }
    const IPAddress currentIP = WiFi.localIP();
    const IPAddress currentMask = WiFi.subnetMask();
    const IPAddress currentGateway = WiFi.gatewayIP();
    const String currentBssid = WiFi.BSSIDstr();
    if (local != currentIP || mask != currentMask || gateway != currentGateway || bssid != currentBssid) {
      invalidate();
      local = currentIP;
      mask = currentMask;
      gateway = currentGateway;
      bssid = currentBssid;
      lastTry = now - INTERVAL_MS;  // Primera busqueda tras DHCP, sin espera.
    }
    if (ready()) return;

    // Un datagrama por iteracion; no esperar respuestas ni drenar en un bucle.
    const int size = listening ? udp.parsePacket() : 0;
    if (size > 0) {
      const IPAddress sender = udp.remoteIP();
      const uint16_t port = udp.remotePort();
      char reply[64];
      const int count = udp.read(reinterpret_cast<uint8_t*>(reply), sizeof(reply) - 1);
      udp.clear();
      if (waiting && now - lastTry < INTERVAL_MS && port == PORT &&
          size == static_cast<int>(strlen(expected)) && count == size &&
          memcmp(reply, expected, count) == 0 && static_cast<uint32_t>(sender) != 0 && sender != local) {
        server = sender;
        udp.stop();
        listening = waiting = false;
        Serial.printf("SERVIDOR DESCUBIERTO: %s; HTTPS %s:%u\n",
                      server.toString().c_str(), SERVER_TLS_NAME, SERVER_TLS_PORT);
        return;
      }
    }
    if (now - lastTry < INTERVAL_MS) return;
    lastTry = now;
    waiting = false;
    if (!listening) listening = udp.begin(PORT) != 0;
    if (!listening) return;  // Reintentar abrir como maximo cada tres segundos.
    IPAddress broadcast;
    for (uint8_t i = 0; i < 4; ++i) broadcast[i] = local[i] | static_cast<uint8_t>(~mask[i]);
    const unsigned long nonce = static_cast<unsigned long>(esp_random());
    char request[64];
    snprintf(request, sizeof(request), "UDP_DISCOVERY_TEST_V1 %08lX", nonce);
    snprintf(expected, sizeof(expected), "UDP_DISCOVERY_REPLY_V1 %08lX", nonce);
    if (udp.beginPacket(broadcast, PORT)) {
      udp.write(reinterpret_cast<const uint8_t*>(request), strlen(request));
      waiting = udp.endPacket() == 1;
    }
    Serial.println(waiting ? "Descubrimiento UDP: broadcast enviado."
                           : "Descubrimiento UDP: fallo de envio; se reintentara.");
  }
};
ServerDiscovery discovery;

// El callback corre en la tarea de eventos del core, no en communication().
// Solo copia datos no sensibles a una cola; nunca conecta, imprime ni toca NVS.
struct WifiNotice {
  arduino_event_id_t event;
  uint32_t at;
  uint32_t ip;
  uint16_t reason;
  uint8_t channel;
  uint8_t auth;
  uint8_t oldAuth;
};
QueueHandle_t wifiNotices = nullptr;
std::atomic<uint32_t> wifiDropped{0};

void onWifiEvent(arduino_event_id_t event, arduino_event_info_t info) {
  WifiNotice notice{};
  notice.event = event;
  notice.at = millis(); // Momento del evento, no del drenaje posterior a HTTP.
  switch (event) {
    case ARDUINO_EVENT_WIFI_STA_START:
    case ARDUINO_EVENT_WIFI_STA_STOP:
    case ARDUINO_EVENT_WIFI_STA_LOST_IP:
      break;
    case ARDUINO_EVENT_WIFI_STA_CONNECTED:
      notice.channel = info.wifi_sta_connected.channel;
      notice.auth = info.wifi_sta_connected.authmode;
      break;
    case ARDUINO_EVENT_WIFI_STA_GOT_IP:
      notice.ip = info.got_ip.ip_info.ip.addr;
      break;
    case ARDUINO_EVENT_WIFI_STA_DISCONNECTED:
      notice.reason = info.wifi_sta_disconnected.reason;
      break;
    case ARDUINO_EVENT_WIFI_STA_AUTHMODE_CHANGE:
      notice.oldAuth = info.wifi_sta_authmode_change.old_mode;
      notice.auth = info.wifi_sta_authmode_change.new_mode;
      break;
    default:
      return;
  }
  if (xQueueSend(wifiNotices, &notice, 0) != pdTRUE)
    wifiDropped.fetch_add(1, std::memory_order_relaxed);
}

void wifiDiagnostics() {
  wifi_config_t config{};
  const esp_err_t result = esp_wifi_get_config(WIFI_IF_STA, &config);
  if (result == ESP_OK) {
    // Nunca imprimir SSID, password, BSSID ni el objeto config completo.
    Serial.printf("Wi-Fi config | auth_min=%u PMF_capaz=%u PMF_obligatorio=%u scan=%u canal_preferido=%u BSSID_fijado=%u\n",
                  static_cast<unsigned>(config.sta.threshold.authmode),
                  static_cast<unsigned>(config.sta.pmf_cfg.capable),
                  static_cast<unsigned>(config.sta.pmf_cfg.required),
                  static_cast<unsigned>(config.sta.scan_method),
                  static_cast<unsigned>(config.sta.channel),
                  static_cast<unsigned>(config.sta.bssid_set));
  } else {
    Serial.printf("Wi-Fi config | error=%s\n", esp_err_to_name(result));
  }
  memset(&config, 0, sizeof(config));
  wifi_ps_type_t powerSave;
  const esp_err_t psResult = esp_wifi_get_ps(&powerSave);
  if (psResult == ESP_OK) Serial.printf("Wi-Fi controlador | ahorro=%d autoReconnect=%u estado=%d\n",
      static_cast<int>(powerSave), WiFi.getAutoReconnect() ? 1U : 0U, WiFi.status());
}

void pollWifiEvents(bool& hasIP, uint32_t& offlineSince, uint32_t& watchdogAt) {
  const uint32_t dropped = wifiDropped.exchange(0, std::memory_order_relaxed);
  if (dropped) Serial.printf("Wi-Fi AVISO | eventos no registrados=%lu; verificar estado actual\n",
                             static_cast<unsigned long>(dropped));
  WifiNotice notice;
  while (xQueueReceive(wifiNotices, &notice, 0) == pdTRUE) {
    Serial.printf("Wi-Fi evento | arranque_ms=%lu | ", static_cast<unsigned long>(notice.at));
    switch (notice.event) {
      case ARDUINO_EVENT_WIFI_STA_START:
        Serial.println("STA_START: radio preparada");
        break;
      case ARDUINO_EVENT_WIFI_STA_CONNECTED:
        Serial.printf("AP conectado | canal=%u auth=%u; esperando IP\n", notice.channel, notice.auth);
        break;
      case ARDUINO_EVENT_WIFI_STA_GOT_IP:
        hasIP = notice.ip != 0;
        Serial.printf("GOT_IP | IP=%s | tiempo_hasta_IP_ms=%lu\n",
                      IPAddress(notice.ip).toString().c_str(), static_cast<unsigned long>(notice.at));
        break;
      case ARDUINO_EVENT_WIFI_STA_AUTHMODE_CHANGE:
        Serial.printf("AUTHMODE_CHANGE | anterior=%u nuevo=%u\n", notice.oldAuth, notice.auth);
        break;
      default:
        discovery.invalidate();
        if (hasIP) offlineSince = watchdogAt = notice.at;
        hasIP = false;
        if (notice.event == ARDUINO_EVENT_WIFI_STA_DISCONNECTED) {
          Serial.printf("desconectado | Motivo: %u (%s) | reconexion exclusiva del core\n",
              notice.reason, WiFi.STA.disconnectReasonName(static_cast<wifi_err_reason_t>(notice.reason)));
          if (notice.reason == WIFI_REASON_AUTH_FAIL) {
            Serial.println("Wi-Fi AUTH_FAIL 202: el core 3.3.8 solo permite su reintento inicial; despues requiere diagnostico/RESET.");
            Serial.println("Comparar auth/PMF del AP y sketch funcional, ajustes del controlador y log del router; no implica por si solo password incorrecto.");
            wifiDiagnostics();
          }
        } else Serial.println("sin IP (LOST_IP/STA_STOP); no enviar telemetria");
        break;
    }
  }
}

// HTTPClient conserva el nombre en la URL/Host; TCP usa la IP descubierta.
// Requiere Arduino-ESP32 3.3.8: sobrecarga IP + nombre para SNI y verificacion SAN.
class DirectServerTlsClient : public WiFiClientSecure {
  IPAddress serverIP;
public:
  explicit DirectServerTlsClient(IPAddress address) : serverIP(address) {}
  using WiFiClientSecure::connect;
  int connect(const char* host, uint16_t port) override {
    return connect(host, port, 3000);
  }
  int connect(const char* host, uint16_t port, int32_t timeout) override {
    if (!host || strcmp(host, SERVER_TLS_NAME) || port != SERVER_TLS_PORT
        || static_cast<uint32_t>(serverIP) == 0
        || !_CA_cert || !_CA_cert[0] || _use_insecure) return 0;
    _timeout = timeout;  // La sobrecarga de seis argumentos utiliza este timeout.
    return WiFiClientSecure::connect(serverIP, port,
                                     SERVER_TLS_NAME, _CA_cert, nullptr, nullptr);
  }
};

void stop(const char* reason) {
  halted = true;
  Serial.printf("STOP: %s; pendiente conservado.\n", reason);
}

bool save(const e1::State& candidate) {
  if (prefs.putBytes("state", &candidate, sizeof(candidate)) != sizeof(candidate)) {
    stop("fallo NVS; no transmitir");
    return false;
  }
  state = candidate;
  return true;
}

bool load() {
  if (!prefs.begin("e1-esp32-01", false)) return false;
  // No tratar un blob corrupto/incompatible como una instalacion nueva.
  if (!prefs.isKey("state")) {
    if (INITIAL_SEQUENCE > e1::MAX_SEQ) return false;
    return save(e1::initial(INITIAL_SEQUENCE));
  }
  return prefs.getBytesLength("state") == sizeof(state)
         && prefs.getBytes("state", &state, sizeof(state)) == sizeof(state) && e1::valid(state);
}

bool configured() {
  if (!strcmp(WIFI_SSID, "REEMPLAZAR") || !strcmp(WIFI_PASSWORD, "REEMPLAZAR")
      || strlen(DEVICE_TOKEN) != 64 || !strstr(TLS_ROOT_CA, "-----BEGIN CERTIFICATE-----")) return false;
  for (const char* p = DEVICE_TOKEN; *p; ++p)
    if (!isxdigit(static_cast<unsigned char>(*p))) return false;
  return true;
}

// Acota tambien respuestas sin Content-Length o chunked; nunca imprime el cuerpo.
class AckBuffer : public Stream {
public:
  char bytes[2049]{};
  size_t used = 0;
  bool overflow = false;
  size_t write(uint8_t byte) override {
    if (used >= sizeof(bytes) - 1) {
      overflow = true;
      return 0;
    }
    bytes[used++] = static_cast<char>(byte);
    return 1;
  }
  int available() override {
    return 0;
  }
  int read() override {
    return -1;
  }
  int peek() override {
    return -1;
  }
  void flush() override {}
};

bool validAck(const AckBuffer& body) {
  cJSON* ack = cJSON_ParseWithLength(body.bytes, body.used + 1);
  if (!ack) return false;
  const cJSON* meta = cJSON_GetObjectItemCaseSensitive(ack, "meta");
  const cJSON* data = cJSON_GetObjectItemCaseSensitive(ack, "data");
  const cJSON* version = cJSON_GetObjectItemCaseSensitive(meta, "api_version");
  const cJSON* node = cJSON_GetObjectItemCaseSensitive(data, "node_id");
  const cJSON* seq = cJSON_GetObjectItemCaseSensitive(data, "seq");
  const bool accepted = cJSON_IsTrue(cJSON_GetObjectItemCaseSensitive(ack, "ok"))
                        && cJSON_IsString(version) && !strcmp(version->valuestring, "v1")
                        && cJSON_IsString(node) && !strcmp(node->valuestring, "esp32-01")
                        && cJSON_IsNumber(seq) && seq->valuedouble == static_cast<double>(state.pendingSeq);
  cJSON_Delete(ack);
  return accepted;
}

e1::Outcome transmit(uint32_t& serverWait) {
  serverWait = 0;
  DirectServerTlsClient tls(discovery.address());
  tls.setCACert(TLS_ROOT_CA);  // CA + hostname + vigencia. Nunca setInsecure().
  tls.setHandshakeTimeout(5);
  HTTPClient http;
  http.setConnectTimeout(3000);
  http.setTimeout(5000);
  http.setFollowRedirects(HTTPC_DISABLE_FOLLOW_REDIRECTS);
  // No sustituir por una URL con IP: HTTPClient debe emitir Host: telemetry.lab.test:8443.
  const String url = String("https://") + SERVER_TLS_NAME + ":" + SERVER_TLS_PORT + "/api/v1/telemetry";
  if (!http.begin(tls, url)) return e1::Outcome::Retry;
  const char* responseHeaders[] = { "Retry-After" };
  http.collectHeaders(responseHeaders, 1);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", String("Bearer ") + DEVICE_TOKEN);
  const int code = http.POST(reinterpret_cast<uint8_t*>(state.body), strlen(state.body));
  if (code == 429 || code == 503) {
    const String after = http.header("Retry-After");
    // Forma delta-seconds; fechas HTTP no se interpretan. Limite operativo: una hora.
    bool digits = after.length() > 0 && after.length() <= 6;
    for (size_t i = 0; i < after.length(); ++i) digits = digits && isdigit(after[i]);
    if (digits) serverWait = static_cast<uint32_t>(min(after.toInt(), 3600L)) * 1000U;
  }
  bool ack = false;
  bool transportLost = code < 0;
  if (code == 200 || code == 201) {
    AckBuffer body;
    if (http.getSize() <= 2048) {
      const int received = http.writeToStream(&body);
      transportLost = received < 0 && !body.overflow;
      if (received >= 0 && !body.overflow) ack = validAck(body);
    }
  }
  Serial.printf("HTTP=%d seq=%llu ack=%u PIR=REAL\n", code,
                static_cast<unsigned long long>(state.pendingSeq), ack ? 1U : 0U);
  http.end();
  if (transportLost) {
    discovery.invalidate();  // Transporte/TLS perdido; no invalidar por HTTP 429/503.
    Serial.println("HTTPS sin conectividad; se repetira el descubrimiento UDP.");
  }
  return e1::classify(code, ack);
}

void communication(void*) {
  if (!configured()) stop("completar secrets.h y CA");
  if (!halted && !load()) stop("NVS invalida/no disponible; no reinicializar automaticamente");
  uint32_t retryStart = 0, retryWait = 0, clockLog = millis() - 15000;
  uint32_t sampleStart = millis() - SAMPLE_MS;
  uint32_t offlineSince = millis(), wifiWatchdogAt = offlineSince;
  bool wifiHasIP = false, sntpStarted = false;
  unsigned attempt = 0;
  if (!halted) {
    wifiNotices = xQueueCreate(16, sizeof(WifiNotice));
    if (!wifiNotices || !WiFi.onEvent(onWifiEvent)) stop("no se pudo registrar eventos Wi-Fi");
    // Una sola autoridad: el core 3.3.8 reconecta tras sus eventos, no este bucle.
    // RAM para configuracion Wi-Fi; no borrar/restaurar NVS ni configuracion AP.
    WiFi.persistent(false);
    if (!halted && (!WiFi.mode(WIFI_STA) || !WiFi.setAutoReconnect(true))) stop("inicializacion Wi-Fi");
    if (!halted) {
      Serial.printf("Wi-Fi inicio intento | arranque_ms=%lu | begin unico; reconexion automatica del core\n",
                    static_cast<unsigned long>(millis()));
      const wl_status_t initialStatus = WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
      Serial.printf("Wi-Fi begin devuelto | estado=%d (no es confirmacion de IP)\n", initialStatus);
      wifiDiagnostics();
    }
  }
  for (;;) {
    if (!halted) {
      pollWifiEvents(wifiHasIP, offlineSince, wifiWatchdogAt);
      const uint32_t now = millis();
      // Comprobar tambien estado vivo: una desconexion puede ocurrir durante HTTP.
      const bool online = WiFi.status() == WL_CONNECTED && static_cast<uint32_t>(WiFi.localIP()) != 0;
      if (wifiHasIP && !online) {
        // Respaldo si se perdio un evento: nunca confiar solo en la cola para enviar.
        wifiHasIP = false;
        offlineSince = wifiWatchdogAt = now;
      }
      if (online) wifiHasIP = true;
      discovery.poll(online, now);
      if (online && !sntpStarted) {
        configTime(0, 0, "pool.ntp.org", "time.nist.gov"); // Solo despues de Wi-Fi + IPv4 valida.
        sntpStarted = true;
        Serial.println("SNTP iniciado tras obtener IP; TLS sigue exigiendo hora valida.");
      }
      if (!online) {
        if (e1::elapsed(now, wifiWatchdogAt, 30000)) {
          Serial.printf("Wi-Fi AVISO sin IP | duracion_ms=%lu estado=%d; no reiniciar una negociacion en curso\n",
                        static_cast<unsigned long>(now - offlineSince), WiFi.status());
          wifiDiagnostics();
          wifiWatchdogAt = now;
        }
      } else if (time(nullptr) < MIN_TLS_TIME) {
        if (e1::elapsed(now, clockLog, 15000)) {
          Serial.println("Esperando hora SNTP para verificar vigencia TLS; no se envia el token.");
          clockLog = now;
        }
      } else if (e1::elapsed(now, retryStart, retryWait)) {
        if (!state.body[0] && e1::elapsed(now, sampleStart, SAMPLE_MS)) {
          e1::State candidate = state;
          const bool motion = pirMotion.load(std::memory_order_relaxed);
          const int rssi = WiFi.RSSI();
          if (!e1::prepare(candidate, rssi, motion)) stop("secuencia agotada o muestra invalida");
          else if (save(candidate))  // Obligatorio antes de tocar la red.
            Serial.printf("Muestra esp32-01 | PIR=%u RSSI=%d seq=%llu\n", motion ? 1U : 0U,
                          rssi, static_cast<unsigned long long>(state.pendingSeq));
        }
        if (!halted && state.body[0] && discovery.ready()) {
          uint32_t serverWait = 0;
          const auto result = transmit(serverWait);  // E/S sincrona acotada, aislada del loop principal.
          retryStart = millis();
          if (result == e1::Outcome::Accepted) {
            e1::State candidate = state;
            memset(candidate.body, 0, sizeof(candidate.body));
            if (save(candidate)) {
              attempt = 0;
              retryWait = 0;
              sampleStart = millis();
            }
          } else if (result == e1::Outcome::Stop) {
            stop("HTTP permanente; revisar credencial/identidad/seq/contrato/TLS proxy");
          } else {
            const uint32_t base = e1::backoff(attempt);
            retryWait = base + esp_random() % 1001;  // 1..61 s, sin cambiar el payload.
            if (serverWait > retryWait) retryWait = serverWait;
            if (attempt < 6) ++attempt;
            Serial.printf("Reintento en %lu ms; mismo contenido.\n", static_cast<unsigned long>(retryWait));
          }
        }
      }
    }
    vTaskDelay(pdMS_TO_TICKS(20));  // Ceder CPU; ninguna espera activa ni delay().
  }
}
}  // namespace

void setup() {
  // El control fisico queda operativo incluso si la comunicacion se detiene.
  digitalWrite(RELAY_PIN, LOW);
  pinMode(RELAY_PIN, OUTPUT);
  pinMode(PIR_PIN, INPUT);
  pirMotion.store(digitalRead(PIR_PIN) == HIGH, std::memory_order_relaxed);
  Serial.begin(115200);
  Serial.println("P11 esp32-01: PIR REAL, RSSI Wi-Fi REAL, HTTPS obligatorio.");
  if (xTaskCreate(communication, "p11-https", 16384, nullptr, 1, nullptr) != pdPASS)
    Serial.println("STOP: no se pudo crear tarea HTTPS; no se transmitira.");
}

void loop() {
  // Solo GPIO y RAM: sin red, NVS, Serial ni esperas de diez segundos.
  static bool lightOn = false;
  static uint32_t lastMotionTime = 0;
  const uint32_t now = millis();
  const bool motion = digitalRead(PIR_PIN) == HIGH;
  pirMotion.store(motion, std::memory_order_relaxed);
  if (motion) {
    lastMotionTime = now;  // Renovar mientras persista la deteccion.
    if (!lightOn) {
      lightOn = true;
      digitalWrite(RELAY_PIN, HIGH);
    }
  } else if (lightOn && e1::elapsed(now, lastMotionTime, HOLD_TIME_MS)) {
    lightOn = false;
    digitalWrite(RELAY_PIN, LOW);
  }
  vTaskDelay(pdMS_TO_TICKS(10));  // Ceder CPU; control fisico ~cada 10 ms.
}
