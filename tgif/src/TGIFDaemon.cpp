#include "TGIFDaemon.hpp"

#include <chrono>
#include <thread>

TGIFDaemon::TGIFDaemon(const Config& config, Logger& log)
    : config_(config), log_(log), backend_(config, log), tlv_(config, log), client_(config, log, tlv_, backend_) {}

bool TGIFDaemon::init() {
    return backend_.init() && tlv_.start() && client_.init();
}

int TGIFDaemon::run() {
    if (!client_.start()) return 1;
    while (true) {
        std::this_thread::sleep_for(std::chrono::seconds(60));
    }
    return 0;
}
