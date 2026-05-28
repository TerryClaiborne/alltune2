#pragma once

#include <fstream>
#include <mutex>
#include <string>

class Logger {
public:
    bool open(const std::string& path, std::string& error);
    void info(const std::string& message);
    void warn(const std::string& message);
    void error(const std::string& message);
private:
    std::ofstream out_;
    std::mutex mutex_;
    void write(const std::string& level, const std::string& message);
};
