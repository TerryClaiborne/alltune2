#include "Config.hpp"
#include "Logger.hpp"
#include "TGIFDaemon.hpp"

#include <iostream>

int main(int argc, char** argv) {
    if (argc != 2) {
        std::cerr << "usage: " << argv[0] << " /path/to/tgifd.ini\n";
        return 2;
    }

    Config config;
    std::string error;
    if (!config.load(argv[1], error)) {
        std::cerr << error << "\n";
        return 1;
    }

    Logger log;
    if (!log.open(config.get("general", "log_file", "./tgifd.log"), error)) {
        std::cerr << error << "\n";
        return 1;
    }

    log.info("TGIFD starting");
    TGIFDaemon daemon(config, log);
    if (!daemon.init()) {
        log.error("daemon initialization failed");
        return 1;
    }
    return daemon.run();
}
