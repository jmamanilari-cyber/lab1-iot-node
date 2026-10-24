// Prueba independiente: Arduino-ESP32 3.3.8, ESP32 Dev Module.
// Compilar agregando firmware/esp32_02_http al include path (ver entrega).
// secrets.h permanece en su ubicacion privada existente; no copiarlo aqui.
#include <WiFi.h>
#include <WiFiUdp.h>
#include <esp_system.h>
#include <secrets.h>

constexpr uint16_t UDP_PORT = 37020;
constexpr uint32_t INTERVAL_MS = 3000;
WiFiUDP udp;
bool listening = false;
bool waitingReply = false;
uint32_t lastAttempt = 0;
IPAddress broadcastIP;
char expectedReply[64];

void setup() {
  Serial.begin(115200);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.println("Conectando al hotspot de 2,4 GHz...");
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    if (listening) {
      udp.stop();
      listening = false;
      waitingReply = false;
      Serial.println("Wi-Fi desconectado; esperando reconexion...");
    }
    delay(100);
    return;
  }

  if (!listening) {
    const IPAddress localIP = WiFi.localIP();
    const IPAddress mask = WiFi.subnetMask();
    for (uint8_t i = 0; i < 4; ++i) {
      broadcastIP[i] = localIP[i] | static_cast<uint8_t>(~mask[i]);
    }
    if (!udp.begin(UDP_PORT)) {
      Serial.println("No se pudo abrir el socket UDP.");
      delay(1000);
      return;
    }
    listening = true;
    lastAttempt = millis() - INTERVAL_MS;
    Serial.printf("IP ESP32: %s; mascara: %s; broadcast: %s; puerto: %u\n",
                  localIP.toString().c_str(), mask.toString().c_str(),
                  broadcastIP.toString().c_str(), UDP_PORT);
  }

  if (millis() - lastAttempt >= INTERVAL_MS) {
    if (waitingReply) Serial.println("Sin respuesta al intento anterior.");
    lastAttempt = millis();
    const uint32_t nonce = esp_random();
    char request[64];
    snprintf(request, sizeof(request), "UDP_DISCOVERY_TEST_V1 %08lX",
             static_cast<unsigned long>(nonce));
    snprintf(expectedReply, sizeof(expectedReply), "UDP_DISCOVERY_REPLY_V1 %08lX",
             static_cast<unsigned long>(nonce));
    waitingReply = false;
    if (udp.beginPacket(broadcastIP, UDP_PORT)) {
      udp.write(reinterpret_cast<const uint8_t*>(request), strlen(request));
      waitingReply = udp.endPacket() == 1;
    }
    Serial.println(waitingReply ? "Broadcast enviado; esperando unicast..."
                               : "Error al enviar broadcast.");
  }

  const int packetSize = udp.parsePacket();
  if (packetSize > 0) {
    const IPAddress sender = udp.remoteIP();
    const uint16_t senderPort = udp.remotePort();
    char reply[64];
    const int count = udp.read(reinterpret_cast<uint8_t*>(reply), sizeof(reply) - 1);
    if (count >= 0) {
      reply[count] = '\0';
      if (waitingReply && senderPort == UDP_PORT &&
          packetSize == static_cast<int>(strlen(expectedReply)) &&
          count == packetSize && strcmp(reply, expectedReply) == 0) {
        Serial.printf("SERVIDOR DESCUBIERTO: %s:%u (respuesta unicast valida)\n",
                      sender.toString().c_str(), senderPort);
        waitingReply = false;
      }
    }
    udp.flush();
  }
  delay(10);
}
