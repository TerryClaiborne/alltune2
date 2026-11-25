# M17D Copyright and Project Notice

## AllTune2 M17D / M17 Implementation

M17D is the AllTune2 M17 backend implementation developed for this project to provide a direct, lightweight M17 network path for AllTune2.

M17D is intended to be maintained as part of the AllTune2 project tree under:

```text
m17d/
```

The M17D implementation includes the M17 reflector/network client, Codec2 audio handling, USRP / AllStar private-node audio bridge logic, helper script integration, runtime readiness/status handling, architecture-specific binaries, and AllTune2 integration needed to make M17 work from the AllTune2 web interface.

## Copyright

Copyright (c) 2026 Terry Claiborne and the AllTune2 project.

Contact: kc3kmv@yahoo.com

All rights are reserved unless a separate license file in this repository states otherwise.

This notice documents that the M17D implementation and the AllTune2 M17 integration are part of the AllTune2 project. It does not grant permission to remove attribution, rebrand the M17D implementation as a separate project, or redistribute it outside the terms provided by the AllTune2 project owner.

## Project Scope

M17D is designed for AllTune2 and DVSwitch-style systems using the private AllStar/DVSwitch audio path.

The goal of M17D is to provide:

- a direct M17 reflector backend for AllTune2
- Codec2-based M17 transmit and receive audio
- ARM64 and AMD64 runtime support
- direct integration with the AllTune2 dashboard and status APIs
- safe takeover and restoration of the private DVSwitch audio path
- clean local runtime handling without committing node-specific private data

## Configuration and Private Data

Node-specific configuration, credentials, callsigns, IDs, and private runtime settings must not be committed to the public repository.

If a dedicated M17D configuration file is introduced later, the live file must remain private and only a sanitized example may be included publicly.

## Attribution

M17D and the AllTune2 M17 integration were developed by Terry Claiborne through extended testing and iteration for the AllTune2 project.

Please preserve this notice and project attribution in redistributed copies or forks.
