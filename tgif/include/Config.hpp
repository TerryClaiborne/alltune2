#pragma once

#include <map>
#include <string>

class Config {
public:
    bool load(const std::string& path, std::string& error);
    std::string get(const std::string& section, const std::string& key, const std::string& fallback = "") const;
    int getInt(const std::string& section, const std::string& key, int fallback) const;
    bool getBool(const std::string& section, const std::string& key, bool fallback) const;
private:
    std::map<std::string, std::map<std::string, std::string>> data_;
};
