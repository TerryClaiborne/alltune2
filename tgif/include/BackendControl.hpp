#pragma once

#include "Config.hpp"
#include "Logger.hpp"

#include <string>

class BackendControl {
public:
    BackendControl(const Config& config, Logger& log);

    bool init();
    bool attach();
    bool detach();
    bool ensureAttached();
    bool isEnabled() const { return enabled_; }
private:
    const Config& config_;
    Logger& log_;

    bool enabled_ = false;
    std::string asteriskBin_ = "/usr/sbin/asterisk";
    std::string myNode_;
    std::string privateNode_;
    std::string autoloadMode_ = "transceive";
    int linkCode_ = 3;
    bool attached_ = false;

    bool execAsterisk(const std::string& cmd);
};
