#pragma once
// Protocolo UDP identico al firmware HTTPS local. Solo lo usa communication().
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
        Serial.printf("SERVIDOR DESCUBIERTO: %s; MQTT TLS %s:%u\n",
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
