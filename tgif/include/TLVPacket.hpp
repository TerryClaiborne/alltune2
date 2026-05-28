#pragma once

#include <cstdint>
#include <vector>

struct TLVPacket {
    std::uint8_t tag = 0;
    std::vector<std::uint8_t> payload;
};
