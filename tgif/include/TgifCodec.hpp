#pragma once

#include <cstdint>
#include <string>
#include <vector>

struct TgifEvent {
    std::string type;
    std::vector<std::uint8_t> salt;
    std::vector<std::uint8_t> raw;
};

class TgifCodec {
public:
    static std::vector<std::uint8_t> encodeLogin(std::uint32_t radioId);
    static std::vector<std::uint8_t> encodeAuthorization(std::uint32_t radioId, const std::string& password, const std::vector<std::uint8_t>& salt);
    static std::vector<std::uint8_t> encodeConfig(std::uint32_t radioId, const std::string& callsign,
                                                  const std::string& rxFrequency, const std::string& txFrequency,
                                                  const std::string& power, const std::string& colorCode,
                                                  const std::string& latitude, const std::string& longitude,
                                                  const std::string& height, const std::string& location,
                                                  const std::string& description, const std::string& slots,
                                                  const std::string& url, const std::string& version,
                                                  const std::string& software);
    static std::vector<std::uint8_t> encodeOptions(std::uint32_t radioId, const std::string& options);
    static std::vector<std::uint8_t> encodePing(std::uint32_t radioId);
    static std::vector<std::uint8_t> encodeClose(std::uint32_t radioId);
    static std::vector<std::uint8_t> encodeDmrdHeader(std::uint8_t seq, std::uint32_t srcId, std::uint32_t dstId,
                                                      std::uint32_t peerId, bool slot2, bool privateCall,
                                                      std::uint32_t streamId);
    static std::vector<std::uint8_t> encodeDmrdVoice(std::uint8_t seq, std::uint32_t srcId, std::uint32_t dstId,
                                                     std::uint32_t peerId, bool slot2, bool privateCall,
                                                     std::uint32_t streamId, int burst, const std::vector<std::uint8_t>& ambe27);
    static std::vector<std::uint8_t> encodeDmrdTerminator(std::uint8_t seq, std::uint32_t srcId, std::uint32_t dstId,
                                                          std::uint32_t peerId, bool slot2, bool privateCall,
                                                          std::uint32_t streamId);
    static TgifEvent parse(const std::vector<std::uint8_t>& packet);
};
