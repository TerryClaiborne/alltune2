#include "TgifCodec.hpp"

#include <array>
#include <cstring>
#include <iomanip>
#include <openssl/sha.h>
#include <sstream>

namespace {
std::vector<std::uint8_t> u32be(std::uint32_t value) {
    return {
        static_cast<std::uint8_t>((value >> 24) & 0xFF),
        static_cast<std::uint8_t>((value >> 16) & 0xFF),
        static_cast<std::uint8_t>((value >> 8) & 0xFF),
        static_cast<std::uint8_t>(value & 0xFF)
    };
}

std::vector<std::uint8_t> u24be(std::uint32_t value) {
    return {
        static_cast<std::uint8_t>((value >> 16) & 0xFF),
        static_cast<std::uint8_t>((value >> 8) & 0xFF),
        static_cast<std::uint8_t>(value & 0xFF)
    };
}

std::string fixedText(const std::string& text, std::size_t width) {
    std::string out = text.substr(0, width);
    if (out.size() < width) out.append(width - out.size(), ' ');
    return out;
}

std::string onlyDigits(const std::string& input) {
    std::string out;
    for (char ch : input) {
        if (ch >= '0' && ch <= '9') out.push_back(ch);
    }
    return out.empty() ? "0" : out;
}

std::string fixedDigits(const std::string& input, std::size_t width) {
    std::string digits = onlyDigits(input);
    if (digits.size() > width) digits = digits.substr(digits.size() - width);
    if (digits.size() < width) digits.insert(digits.begin(), width - digits.size(), '0');
    return digits;
}

void setBit(std::vector<std::uint8_t>& out, std::size_t idx, std::uint8_t bit) {
    std::size_t byteIndex = idx / 8;
    std::size_t bitInByte = 7 - (idx % 8);
    out[byteIndex] = static_cast<std::uint8_t>(out[byteIndex] | ((bit & 0x01) << bitInByte));
}

std::vector<std::uint8_t> packAmbe72IntoDmrdPayload(const std::vector<std::uint8_t>& ambe27, int burst) {
    std::vector<std::uint8_t> payload(33, 0);
    if (ambe27.size() != 27) return payload;

    static const std::array<std::uint8_t, 6> kBsVoiceSync{
        0x75, 0x5F, 0xD7, 0xDF, 0x75, 0xF7
    };

    auto getBitFromBytes = [&](const auto& bytes, std::size_t idx) -> std::uint8_t {
        std::size_t byteIndex = idx / 8;
        std::size_t bitInByte = 7 - (idx % 8);
        return static_cast<std::uint8_t>((bytes[byteIndex] >> bitInByte) & 0x01);
    };

    std::size_t ambeIdx = 0;
    for (std::size_t i = 0; i < 108 && ambeIdx < 216; ++i) {
        setBit(payload, i, getBitFromBytes(ambe27, ambeIdx++));
    }

    if (burst == 0) {
        for (std::size_t i = 0; i < 48; ++i) {
            setBit(payload, 108 + i, getBitFromBytes(kBsVoiceSync, i));
        }
    }

    for (std::size_t i = 156; i < 264 && ambeIdx < 216; ++i) {
        setBit(payload, i, getBitFromBytes(ambe27, ambeIdx++));
    }

    return payload;
}

std::vector<std::uint8_t> makeDmrd(std::uint8_t seq, std::uint32_t srcId, std::uint32_t dstId, std::uint32_t peerId,
                                   std::uint8_t bits, std::uint32_t streamId, const std::vector<std::uint8_t>& payload33) {
    std::vector<std::uint8_t> out{'D','M','R','D'};
    out.push_back(seq);
    auto src = u24be(srcId);
    auto dst = u24be(dstId);
    auto peer = u32be(peerId);
    auto stream = u32be(streamId);
    out.insert(out.end(), src.begin(), src.end());
    out.insert(out.end(), dst.begin(), dst.end());
    out.insert(out.end(), peer.begin(), peer.end());
    out.push_back(bits);
    out.insert(out.end(), stream.begin(), stream.end());
    auto payload = payload33;
    if (payload.size() < 33) payload.resize(33, 0);
    out.insert(out.end(), payload.begin(), payload.begin() + 33);
    out.push_back(0x00);
    out.push_back(0x00);
    return out;
}
}

std::vector<std::uint8_t> TgifCodec::encodeLogin(std::uint32_t radioId) {
    std::vector<std::uint8_t> out{'R','P','T','L'};
    auto id = u32be(radioId);
    out.insert(out.end(), id.begin(), id.end());
    return out;
}

std::vector<std::uint8_t> TgifCodec::encodeAuthorization(std::uint32_t radioId, const std::string& password, const std::vector<std::uint8_t>& salt) {
    std::vector<std::uint8_t> out{'R','P','T','K'};
    auto id = u32be(radioId);
    out.insert(out.end(), id.begin(), id.end());

    std::vector<std::uint8_t> data = salt;
    data.insert(data.end(), password.begin(), password.end());
    std::array<unsigned char, SHA256_DIGEST_LENGTH> digest{};
    SHA256(data.data(), data.size(), digest.data());
    out.insert(out.end(), digest.begin(), digest.end());
    return out;
}

std::vector<std::uint8_t> TgifCodec::encodeConfig(std::uint32_t radioId, const std::string& callsign, const std::string& rxFrequency,
                                                  const std::string& txFrequency, const std::string& power, const std::string& colorCode,
                                                  const std::string& latitude, const std::string& longitude, const std::string& height,
                                                  const std::string& location, const std::string& description,
                                                  const std::string& slots, const std::string& url,
                                                  const std::string& version, const std::string& software) {
    std::ostringstream lat;
    lat << std::fixed << std::setprecision(5) << std::stod(latitude.empty() ? "0" : latitude);
    std::ostringstream lon;
    lon << std::fixed << std::setprecision(5) << std::stod(longitude.empty() ? "0" : longitude);

    std::string payload =
        fixedText(callsign, 8) +
        fixedDigits(rxFrequency, 9) +
        fixedDigits(txFrequency, 9) +
        fixedDigits(power, 2) +
        fixedDigits(colorCode, 2) +
        fixedText(lat.str(), 8) +
        fixedText(lon.str(), 9) +
        fixedDigits(height, 3) +
        fixedText(location, 20) +
        fixedText(description, 19) +
        fixedText(slots.empty() ? "4" : slots.substr(0,1), 1) +
        fixedText(url, 124) +
        fixedText(version, 40) +
        fixedText(software, 40);

    std::vector<std::uint8_t> out{'R','P','T','C'};
    auto id = u32be(radioId);
    out.insert(out.end(), id.begin(), id.end());
    out.insert(out.end(), payload.begin(), payload.end());
    return out;
}

std::vector<std::uint8_t> TgifCodec::encodeOptions(std::uint32_t radioId, const std::string& options) {
    std::vector<std::uint8_t> out{'R','P','T','O'};
    auto id = u32be(radioId);
    out.insert(out.end(), id.begin(), id.end());
    out.insert(out.end(), options.begin(), options.end());
    return out;
}

std::vector<std::uint8_t> TgifCodec::encodePing(std::uint32_t radioId) {
    std::vector<std::uint8_t> out{'R','P','T','P','I','N','G'};
    auto id = u32be(radioId);
    out.insert(out.end(), id.begin(), id.end());
    return out;
}

std::vector<std::uint8_t> TgifCodec::encodeClose(std::uint32_t radioId) {
    std::vector<std::uint8_t> out{'R','P','T','C','L'};
    auto id = u32be(radioId);
    out.insert(out.end(), id.begin(), id.end());
    return out;
}

std::vector<std::uint8_t> TgifCodec::encodeDmrdHeader(std::uint8_t seq, std::uint32_t srcId, std::uint32_t dstId,
                                                      std::uint32_t peerId, bool slot2, bool privateCall,
                                                      std::uint32_t streamId) {
    std::uint8_t bits = static_cast<std::uint8_t>((slot2 ? 0x80 : 0x00) | (privateCall ? 0x40 : 0x00) | 0x10 | 0x01);
    return makeDmrd(seq, srcId, dstId, peerId, bits, streamId, std::vector<std::uint8_t>(33, 0));
}

std::vector<std::uint8_t> TgifCodec::encodeDmrdVoice(std::uint8_t seq, std::uint32_t srcId, std::uint32_t dstId,
                                                     std::uint32_t peerId, bool slot2, bool privateCall,
                                                     std::uint32_t streamId, int burst, const std::vector<std::uint8_t>& ambe27) {
    int safeBurst = burst;
    if (safeBurst < 0) safeBurst = 0;
    if (safeBurst > 5) safeBurst = safeBurst % 6;
    std::uint8_t bits = static_cast<std::uint8_t>((slot2 ? 0x80 : 0x00) | (privateCall ? 0x40 : 0x00) | (safeBurst & 0x0F));
    return makeDmrd(seq, srcId, dstId, peerId, bits, streamId, packAmbe72IntoDmrdPayload(ambe27, safeBurst));
}

std::vector<std::uint8_t> TgifCodec::encodeDmrdTerminator(std::uint8_t seq, std::uint32_t srcId, std::uint32_t dstId,
                                                          std::uint32_t peerId, bool slot2, bool privateCall,
                                                          std::uint32_t streamId) {
    std::uint8_t bits = static_cast<std::uint8_t>((slot2 ? 0x80 : 0x00) | (privateCall ? 0x40 : 0x00) | 0x10 | 0x02);
    return makeDmrd(seq, srcId, dstId, peerId, bits, streamId, std::vector<std::uint8_t>(33, 0));
}

TgifEvent TgifCodec::parse(const std::vector<std::uint8_t>& packet) {
    TgifEvent event{};
    event.raw = packet;
    if (packet.size() >= 10 && std::memcmp(packet.data(), "RPTACK", 6) == 0) {
        event.type = "rptack";
        event.salt.assign(packet.begin() + 6, packet.begin() + 10);
        return event;
    }
    if (packet.size() >= 6 && std::memcmp(packet.data(), "MSTNAK", 6) == 0) {
        event.type = "mstnak";
        return event;
    }
    if (packet.size() >= 5 && std::memcmp(packet.data(), "MSTCL", 5) == 0) {
        event.type = "mstcl";
        return event;
    }
    if (packet.size() >= 7 && std::memcmp(packet.data(), "MSTPONG", 7) == 0) {
        event.type = "mstpong";
        return event;
    }
    if (packet.size() >= 4 && std::memcmp(packet.data(), "DMRD", 4) == 0) {
        event.type = "dmrd";
        return event;
    }
    event.type = "unknown";
    return event;
}
