#pragma once

#include "BackendControl.hpp"
#include "Config.hpp"
#include "Logger.hpp"
#include "TGIFClient.hpp"
#include "TLVBridge.hpp"

class TGIFDaemon {
public:
    TGIFDaemon(const Config& config, Logger& log);
    bool init();
    int run();
private:
    const Config& config_;
    Logger& log_;
    BackendControl backend_;
    TLVBridge tlv_;
    TGIFClient client_;
};
