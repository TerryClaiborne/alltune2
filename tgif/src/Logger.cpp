#include "Logger.hpp"

#include <chrono>
#include <ctime>
#include <iomanip>
#include <iostream>
#include <sstream>

bool Logger::open(const std::string& path, std::string& error) {
    out_.open(path, std::ios::app);
    if (!out_) {
        error = "cannot open log file: " + path;
        return false;
    }
    return true;
}

void Logger::info(const std::string& message) { write("INFO", message); }
void Logger::warn(const std::string& message) { write("WARN", message); }
void Logger::error(const std::string& message) { write("ERROR", message); }

void Logger::write(const std::string& level, const std::string& message) {
    std::lock_guard<std::mutex> lock(mutex_);
    auto now = std::chrono::system_clock::now();
    std::time_t t = std::chrono::system_clock::to_time_t(now);
    std::tm tm{};
    localtime_r(&t, &tm);
    std::ostringstream line;
    line << std::put_time(&tm, "%Y-%m-%d %H:%M:%S") << " [" << level << "] " << message << "\n";
    std::cerr << line.str();
    if (out_) {
        out_ << line.str();
        out_.flush();
    }
}
