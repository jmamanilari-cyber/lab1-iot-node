#include "../firmware/esp32_02_http/TelemetryState.h"
#include <assert.h>
#include <iostream>

int main() {
  using namespace e1;
  State disk = initial(1234);
  State ram = disk;
  assert(prepare(ram, -61));
  // Corte antes de commit NVS: aun no se permite transmitir; se puede reservar otra vez.
  State reboot = disk;
  assert(prepare(reboot, -72));
  assert(reboot.pendingSeq == ram.pendingSeq);
  disk = ram; // commit atomico del blob antes de transmitir.
  reboot = disk;
  assert(valid(reboot) && reboot.next == 1235);
  assert(!prepare(reboot, -40)); // No reemplazar un pendiente ni cambiar su RSSI.
  assert(!strcmp(reboot.body, ram.body));
  // ACK perdido y reboot despues de commit SQL: reenvio identico, no nueva muestra.
  assert(classify(-1, false) == Outcome::Retry);
  assert(classify(200, false) == Outcome::Retry);
  assert(classify(200, true) == Outcome::Accepted);
  assert(classify(201, true) == Outcome::Accepted);
  for (int status : {408, 429, 500, 503}) assert(classify(status, false) == Outcome::Retry);
  for (int status : {204, 301, 307, 400, 401, 403, 404, 409, 422})
    assert(classify(status, false) == Outcome::Stop);
  // ACK confirmado y borrado persistente: siguiente secuencia, sin reutilizarla.
  memset(reboot.body, 0, sizeof(reboot.body));
  disk = reboot;
  reboot = disk;
  assert(prepare(reboot, -42) && reboot.pendingSeq == 1235);
  State exhausted = initial(MAX_SEQ);
  assert(prepare(exhausted, -127));
  exhausted.body[0] = '\0';
  assert(!prepare(exhausted, 0));
  State bad = initial(0);
  bad.magic = 0;
  assert(!valid(bad));
  bad = initial(0);
  assert(!prepare(bad, -128) && !prepare(bad, 1));
  assert(backoff(0) == 1000 && backoff(6) == 60000 && backoff(100) == 60000);
  assert(elapsed(20, UINT32_MAX - 9, 30)); // rollover millis().
  // Fixture real emitido por el generador C++, validable con TelemetryService PHP.
  std::cout << ram.body << '\n';
  std::cerr << "PASS E1: secuencias, recuperacion, reintentos, errores y rollover.\n";
}
