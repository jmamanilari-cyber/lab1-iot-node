#pragma once
#include <stdint.h>
#include <stdio.h>
#include <string.h>

namespace e1 {
constexpr uint64_t MAX_SEQ = 9007199254740991ULL;
constexpr uint32_t MAGIC = 0x50313131;  // Estado P11, independiente de E1 esp32-02.
constexpr size_t BODY_SIZE = 320;

// Un unico blob NVS: reserva de secuencia y contenido se confirman juntos.
struct State {
  uint32_t magic;
  uint32_t reserved;
  uint64_t next;
  uint64_t pendingSeq;
  char body[BODY_SIZE];
};

inline bool valid(const State& s) {
  return s.magic == MAGIC && s.reserved == 0 && s.next <= MAX_SEQ + 1
      && memchr(s.body, '\0', sizeof(s.body)) != nullptr
      && (!s.body[0] || (s.pendingSeq <= MAX_SEQ && s.next == s.pendingSeq + 1));
}

inline State initial(uint64_t next) {
  State s{};
  s.magic = MAGIC;
  s.next = next;
  return s;
}

inline bool prepare(State& s, int rssi, bool motion) {
  if (!valid(s) || s.body[0] || s.next > MAX_SEQ || rssi < -127 || rssi > 0) return false;
  State candidate = s;
  candidate.pendingSeq = s.next;
  candidate.next++;
  // Valor instantaneo del PIR fisico; no confundir con el rele temporizado.
  const int length = snprintf(candidate.body, sizeof(candidate.body),
      "{\"node_id\":\"esp32-01\",\"seq\":%llu,\"timestamp\":null,\"value\":%u,"
      "\"rssi\":%d,\"extensions\":{\"pir_source\":\"gpio27\"}}",
      static_cast<unsigned long long>(candidate.pendingSeq),
      motion ? 1U : 0U, rssi);
  if (length < 0 || static_cast<size_t>(length) >= sizeof(candidate.body)) return false;
  s = candidate;
  return true;
}

enum class Outcome { Accepted, Retry, Stop };
inline Outcome classify(int code, bool validAck) {
  if (code == 200 || code == 201) return validAck ? Outcome::Accepted : Outcome::Retry;
  if (code <= 0 || code == 408 || code == 429 || (code >= 500 && code <= 599)) return Outcome::Retry;
  return Outcome::Stop; // Incluye 3xx, 401/403, 409 y errores del contrato.
}

inline uint32_t backoff(unsigned attempt) {
  return attempt >= 6 ? 60000U : (1000U << attempt);
}
inline bool elapsed(uint32_t now, uint32_t start, uint32_t interval) {
  return static_cast<uint32_t>(now - start) >= interval;
}
} // namespace e1
