#include "BackendControl.hpp"

#include <cstdlib>

BackendControl::BackendControl(const Config& config, Logger& log)
    : config_(config), log_(log) {}

bool BackendControl::init() {
    enabled_ = config_.getBool("private_node", "enabled", false);
    asteriskBin_ = config_.get("private_node", "asterisk_bin", "/usr/sbin/asterisk");
    myNode_ = config_.get("private_node", "mynode", "");
    privateNode_ = config_.get("private_node", "private_node", "");
    autoloadMode_ = config_.get("private_node", "autoload_mode", "transceive");
    linkCode_ = (autoloadMode_ == "local_monitor") ? 8 : 3;

    if (!enabled_) {
        log_.info("private node control disabled");
        return true;
    }
    if (myNode_.empty() || privateNode_.empty()) {
        log_.warn("private node control enabled but mynode/private_node missing; disabling");
        enabled_ = false;
    }
    return true;
}

bool BackendControl::execAsterisk(const std::string& cmd) {
    std::string full = asteriskBin_ + " -rx \"" + cmd + "\" >/dev/null 2>&1";
    int rc = std::system(full.c_str());
    if (rc != 0) {
        log_.warn("asterisk command failed rc=" + std::to_string(rc) + ": " + cmd);
        return false;
    }
    return true;
}

bool BackendControl::attach() {
    if (!enabled_) return true;
    if (attached_) return true;
    std::string cmd = "rpt fun " + myNode_ + " *" + std::to_string(linkCode_) + privateNode_;
    if (!execAsterisk(cmd)) return false;
    attached_ = true;
    log_.info("private node attached: " + privateNode_);
    return true;
}

bool BackendControl::detach() {
    if (!enabled_) return true;
    if (!attached_) return true;
    std::string cmd = "rpt fun " + myNode_ + " *1" + privateNode_;
    if (!execAsterisk(cmd)) return false;
    attached_ = false;
    log_.info("private node detached: " + privateNode_);
    return true;
}

bool BackendControl::ensureAttached() {
    return attach();
}
