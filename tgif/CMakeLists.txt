cmake_minimum_required(VERSION 3.16)
project(tgifd VERSION 0.2.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)

find_package(OpenSSL REQUIRED)

add_executable(tgifd
  src/main.cpp
  src/Config.cpp
  src/Logger.cpp
  src/UdpSocket.cpp
  src/TgifCodec.cpp
  src/TGIFClient.cpp
  src/TLVBridge.cpp
  src/TGIFDaemon.cpp
  src/BackendControl.cpp
)

target_include_directories(tgifd PRIVATE include)
target_link_libraries(tgifd PRIVATE OpenSSL::Crypto pthread)
