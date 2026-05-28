#include "Config.hpp"

#include <algorithm>
#include <cctype>
#include <fstream>

namespace {
std::string trim(std::string s) {
    auto pred = [](unsigned char ch) { return !std::isspace(ch); };
    s.erase(s.begin(), std::find_if(s.begin(), s.end(), pred));
    s.erase(std::find_if(s.rbegin(), s.rend(), pred).base(), s.end());
    if (s.size() >= 2 && ((s.front() == '"' && s.back() == '"') || (s.front() == '\'' && s.back() == '\''))) {
        s = s.substr(1, s.size() - 2);
    }
    return s;
}
std::string lower(std::string s) {
    std::transform(s.begin(), s.end(), s.begin(), [](unsigned char c) { return static_cast<char>(std::tolower(c)); });
    return s;
}
}

bool Config::load(const std::string& path, std::string& error) {
    std::ifstream in(path);
    if (!in) {
        error = "cannot open config file: " + path;
        return false;
    }
    data_.clear();
    std::string line;
    std::string section;
    while (std::getline(in, line)) {
        line = trim(line);
        if (line.empty() || line[0] == '#' || line[0] == ';') continue;
        if (line.front() == '[' && line.back() == ']') {
            section = trim(line.substr(1, line.size() - 2));
            continue;
        }
        auto pos = line.find('=');
        if (pos == std::string::npos) continue;
        auto key = trim(line.substr(0, pos));
        auto value = trim(line.substr(pos + 1));
        data_[section][key] = value;
    }
    return true;
}

std::string Config::get(const std::string& section, const std::string& key, const std::string& fallback) const {
    auto s = data_.find(section);
    if (s == data_.end()) return fallback;
    auto k = s->second.find(key);
    if (k == s->second.end()) return fallback;
    return k->second;
}

int Config::getInt(const std::string& section, const std::string& key, int fallback) const {
    try { return std::stoi(get(section, key, std::to_string(fallback))); }
    catch (...) { return fallback; }
}

bool Config::getBool(const std::string& section, const std::string& key, bool fallback) const {
    auto value = lower(get(section, key, fallback ? "yes" : "no"));
    if (value == "1" || value == "yes" || value == "true" || value == "on") return true;
    if (value == "0" || value == "no" || value == "false" || value == "off") return false;
    return fallback;
}
