// ESP32 Dev Module, Arduino-ESP32 3.3.8; esp-mqtt incluido en el core.
#include <WiFi.h>
#include <WiFiUdp.h>
#include <Preferences.h>
#include <mqtt_client.h>
#include <esp_system.h>
#include <esp_log.h>
#include <atomic>
#include <freertos/queue.h>
#include <time.h>
#include "TelemetryState.h"
#include "secrets.h"

namespace {
constexpr uint32_t SAMPLE_MS = 10000;
constexpr time_t MIN_TLS_TIME = 1767225600;
constexpr char SERVER_TLS_NAME[] = "telemetry.lab.test";
constexpr uint16_t SERVER_TLS_PORT = 8883;
constexpr char TOPIC[] = "lab2/esp32-03/telemetry";
constexpr char CLIENT_ID[] = "lab2-esp32-03-d2";
#include "ServerDiscovery.h"
ServerDiscovery discovery;
Preferences prefs;
e1::State state;
bool halted = false;
std::atomic<bool> wifiChanged{false}, mqttOverflow{false};

struct Sample { int rssi; };
struct MqttNotice { int event; int id; uint32_t generation; };
QueueHandle_t samples = nullptr, notices = nullptr;
esp_mqtt_client_handle_t mqtt = nullptr;
String brokerAddress;
uint32_t generation = 0;

// Mismo ABI que HTTPS #3: no migrar, borrar ni reinicializar el blob existente.
static_assert(sizeof(e1::State) == 344 && offsetof(e1::State, body) == 24, "ABI NVS HTTPS #3 incompatible");
static_assert(e1::MAGIC == 0x50313233, "Conservar magic P12 esp32-03");

void onWifi(arduino_event_id_t event, arduino_event_info_t) {
  if (event == ARDUINO_EVENT_WIFI_STA_DISCONNECTED || event == ARDUINO_EVENT_WIFI_STA_LOST_IP ||
      event == ARDUINO_EVENT_WIFI_STA_STOP || event == ARDUINO_EVENT_WIFI_STA_GOT_IP)
    wifiChanged.store(true, std::memory_order_relaxed);
}

void onMqtt(void* arg, esp_event_base_t, int32_t event, void* data) {
  // No NVS, Serial ni operaciones de red desde el callback de esp-mqtt.
  if (event != MQTT_EVENT_CONNECTED && event != MQTT_EVENT_DISCONNECTED &&
      event != MQTT_EVENT_PUBLISHED && event != MQTT_EVENT_ERROR) return;
  const auto info = static_cast<esp_mqtt_event_handle_t>(data);
  const MqttNotice notice{static_cast<int>(event), info->msg_id,
                         static_cast<uint32_t>(reinterpret_cast<uintptr_t>(arg))};
  if (xQueueSend(notices, &notice, 0) != pdTRUE) mqttOverflow.store(true, std::memory_order_relaxed);
}

void stop(const char* reason) {
  halted = true;
  Serial.printf("STOP: %s; pendiente NVS conservado.\n", reason);
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
  if (!prefs.begin("e1-esp32-03", false)) return false;
  if (!prefs.isKey("state")) {
    if (INITIAL_SEQUENCE > e1::MAX_SEQ) return false;
    return save(e1::initial(INITIAL_SEQUENCE));
  }
  return prefs.getBytesLength("state") == sizeof(state) &&
         prefs.getBytes("state", &state, sizeof(state)) == sizeof(state) && e1::valid(state);
}
bool configured() {
  return strcmp(WIFI_SSID, "REEMPLAZAR") && strcmp(WIFI_PASSWORD, "REEMPLAZAR") &&
         !strcmp(MQTT_USERNAME, "esp32-03") && MQTT_PASSWORD[0] &&
         strcmp(MQTT_PASSWORD, "REEMPLAZAR") && strstr(TLS_ROOT_CA, "-----BEGIN CERTIFICATE-----");
}

void destroyMqtt() {
  // Solo communication(): puede esperar la tarea de red, nunca bloquea loop()/muestreo.
  if (mqtt) {
    esp_mqtt_client_destroy(mqtt);
    mqtt = nullptr;
  }
}
bool startMqtt() {
  brokerAddress = discovery.address().toString();
  esp_mqtt_client_config_t config{};
  config.broker.address.hostname = brokerAddress.c_str(); // Solo destino TCP obtenido por UDP.
  config.broker.address.port = SERVER_TLS_PORT;
  config.broker.address.transport = MQTT_TRANSPORT_OVER_SSL;
  config.broker.verification.certificate = TLS_ROOT_CA;
  config.broker.verification.common_name = SERVER_TLS_NAME; // esp-tls: SNI + validacion SAN/CN.
  config.broker.verification.skip_cert_common_name_check = false;
  config.credentials.client_id = CLIENT_ID;
  config.credentials.username = MQTT_USERNAME;
  config.credentials.authentication.password = MQTT_PASSWORD;
  // El core incluye MQTT 3.1.1, no MQTT 5. PUBACK no tiene reason code ni confirma SQL/ACL.
  config.session.protocol_ver = MQTT_PROTOCOL_V_3_1_1;
  config.session.disable_clean_session = false;
  config.session.keepalive = 15;
  config.session.message_retransmit_timeout = 10000;
  config.network.disable_auto_reconnect = true; // Una autoridad: backoff + nuevo UDP abajo.
  config.network.timeout_ms = 5000;
  config.buffer.size = 512;
  config.outbox.limit = 1024; // Solo un pendiente; NVS es la fuente durable.
  mqtt = esp_mqtt_client_init(&config);
  if (!mqtt) return false;
  ++generation;
  if (esp_mqtt_client_register_event(mqtt, MQTT_EVENT_ANY, onMqtt,
          reinterpret_cast<void*>(static_cast<uintptr_t>(generation))) != ESP_OK ||
      esp_mqtt_client_start(mqtt) != ESP_OK) {
    destroyMqtt();
    return false;
  }
  return true;
}

void communication(void*) {
  // Evitar logs internos verbosos de MQTT/TLS con posibles detalles de autenticacion.
  esp_log_level_set("mqtt_client", ESP_LOG_NONE);
  if (!configured()) stop("completar secrets.h MQTT y CA");
  if (!halted && !load()) stop("NVS incompatible/corrupta; no reinicializar");
  if (!halted && !WiFi.onEvent(onWifi)) stop("eventos Wi-Fi no disponibles");
  if (!halted) {
    WiFi.persistent(false);
    if (!WiFi.mode(WIFI_STA) || !WiFi.setAutoReconnect(true)) stop("inicializacion Wi-Fi");
    else WiFi.begin(WIFI_SSID, WIFI_PASSWORD); // Sin bucles manuales de reconexion Wi-Fi.
  }
  uint32_t retryStart = 0, retryWait = 0, operationStart = 0;
  uint32_t clockLog = millis() - 15000;
  unsigned attempt = 0;
  int inFlight = -1;
  bool connected = false, sntpStarted = false;
  for (;;) {
    if (!halted) {
      bool failed = mqttOverflow.exchange(false, std::memory_order_relaxed);
      if (wifiChanged.exchange(false, std::memory_order_relaxed)) {
        discovery.invalidate();
        if (mqtt) failed = true;
      }
      const uint32_t now = millis();
      const bool online = WiFi.status() == WL_CONNECTED && static_cast<uint32_t>(WiFi.localIP()) != 0;
      discovery.poll(online, now);
      if (online && !sntpStarted) {
        configTime(0, 0, "pool.ntp.org", "time.nist.gov");
        sntpStarted = true;
      }
      const bool clockValid = time(nullptr) >= MIN_TLS_TIME;
      if (online && !clockValid && e1::elapsed(now, clockLog, 15000)) {
        Serial.println("Esperando SNTP; MQTT/TLS exige hora valida.");
        clockLog = now;
      }
      if (mqtt && (!online || !clockValid || !discovery.ready() ||
                   brokerAddress != discovery.address().toString())) failed = true;

      MqttNotice notice;
      while (xQueueReceive(notices, &notice, 0) == pdTRUE) {
        if (!mqtt || notice.generation != generation) continue;
        if (notice.event == MQTT_EVENT_CONNECTED) {
          connected = true;
          Serial.println("MQTT TLS conectado: esp32-03, QoS 1, topico exclusivo.");
        } else if (notice.event == MQTT_EVENT_ERROR || notice.event == MQTT_EVENT_DISCONNECTED) {
          failed = true;
        } else if (notice.event == MQTT_EVENT_PUBLISHED && notice.id == inFlight && state.body[0]) {
          // ACK de TRANSPORTE solamente. El backend actual no devuelve ACK de MariaDB.
          Serial.printf("PUBACK broker seq=%llu; MariaDB=NO_CONFIRMADA.\n",
                        static_cast<unsigned long long>(state.pendingSeq));
          e1::State candidate = state;
          memset(candidate.body, 0, sizeof(candidate.body));
          if (save(candidate)) {
            inFlight = -1;
            attempt = 0;
            retryWait = 0;
          }
        }
      }
      if (mqtt && ((!connected && e1::elapsed(now, operationStart, 15000)) ||
                   (inFlight >= 0 && e1::elapsed(now, operationStart, 20000)))) failed = true;
      if (failed) {
        destroyMqtt();
        connected = false;
        inFlight = -1;
        discovery.invalidate();
        retryStart = millis();
        retryWait = e1::backoff(attempt) + esp_random() % 1001;
        if (attempt < 6) ++attempt;
        Serial.printf("Reintento MQTT/UDP en %lu ms; pendiente sin alterar.\n",
                      static_cast<unsigned long>(retryWait));
      }

      // Una ranura durable compatible con HTTPS; durante fallos se conserva el body
      // y se coalescen muestras nuevas en la ultima muestra RAM, sin bloquear loop().
      Sample sample;
      if (!halted && !state.body[0] && xQueueReceive(samples, &sample, 0) == pdTRUE) {
        e1::State candidate = state;
        if (!e1::prepare(candidate, sample.rssi)) stop("muestra/secuencia invalida");
        else if (save(candidate)) // Antes de encolar cualquier byte para MQTT.
          Serial.printf("Muestra NVS seq=%llu value=%u RSSI=%d PIR=SIMULADO\n",
                        static_cast<unsigned long long>(state.pendingSeq),
                        static_cast<unsigned>(state.pendingSeq % 2), sample.rssi);
      }
      if (!halted && online && clockValid && discovery.ready() && !mqtt &&
          e1::elapsed(millis(), retryStart, retryWait)) {
        operationStart = millis();
        if (!startMqtt()) {
          // El siguiente ciclo aplica el mismo backoff que a un fallo de transporte.
          mqttOverflow.store(true, std::memory_order_relaxed);
        }
      }
      if (!halted && mqtt && connected && inFlight < 0 && state.body[0]) {
        // enqueue() no escribe sockets en esta tarea. La tarea esp-mqtt envia QoS 1.
        inFlight = esp_mqtt_client_enqueue(mqtt, TOPIC, state.body, strlen(state.body), 1, 0, true);
        operationStart = millis();
        if (inFlight < 0) mqttOverflow.store(true, std::memory_order_relaxed);
        else Serial.printf("MQTT encolado seq=%llu; esperando PUBACK.\n",
                           static_cast<unsigned long long>(state.pendingSeq));
      }
    } else if (mqtt) destroyMqtt();
    vTaskDelay(pdMS_TO_TICKS(20));
  }
}
} // namespace

void setup() {
  Serial.begin(115200);
  Serial.println("D2 esp32-03: PIR SIMULADO, RSSI REAL, MQTT TLS 8883 QoS 1.");
  samples = xQueueCreate(1, sizeof(Sample));
  notices = xQueueCreate(16, sizeof(MqttNotice));
  if (!samples || !notices || xTaskCreate(communication, "d2-mqtt", 16384, nullptr, 1, nullptr) != pdPASS)
    Serial.println("STOP: no se pudo crear tarea/colas MQTT; no se transmitira.");
}

void loop() {
  // Muestreo independiente: ninguna conexion, NVS ni espera de red en loop().
  static uint32_t lastSample = millis() - SAMPLE_MS;
  const uint32_t now = millis();
  if (e1::elapsed(now, lastSample, SAMPLE_MS)) {
    lastSample = now;
    if (samples && WiFi.status() == WL_CONNECTED && static_cast<uint32_t>(WiFi.localIP()) != 0) {
      const Sample sample{WiFi.RSSI()};
      xQueueOverwrite(samples, &sample);
      // PIR por paridad de secuencia, identico al contrato HTTPS, al reservar NVS.
      Serial.printf("Muestreo RSSI=%d; PIR SIMULADO por secuencia.\n", sample.rssi);
    }
  }
  vTaskDelay(pdMS_TO_TICKS(20));
}
