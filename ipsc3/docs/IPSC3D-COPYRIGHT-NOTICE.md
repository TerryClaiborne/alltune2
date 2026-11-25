# IPSC3D Copyright and Project Notice

## AllTune2 IPSC3D / IPSC3 Implementation

IPSC3D is the AllTune2 IPSC3 backend implementation developed for this project to provide a direct, lightweight DMR+/IPSC3 network path for AllTune2.

IPSC3D is intended to be maintained as part of the AllTune2 project tree under:

```text
ipsc3/
```

The IPSC3D implementation includes the IPSC3/Homebrew network client, DMRD handling, TLV / Analog_Bridge audio bridge logic, helper script integration, runtime status handling, configuration examples, and AllTune2 installer support needed to make IPSC3 work from the AllTune2 web interface.

## Copyright

Copyright (c) 2026 Terry Claiborne and the AllTune2 project.

Contact: kc3kmv@yahoo.com

All rights are reserved unless a separate license file in this repository states otherwise.

This notice documents that the IPSC3D implementation and the AllTune2 IPSC3 integration are part of the AllTune2 project. It does not grant permission to remove attribution, rebrand the IPSC3D implementation as a separate project, or redistribute it outside the terms provided by the AllTune2 project owner.

## Project Scope

IPSC3D is designed for AllTune2 and DVSwitch-style systems using Analog_Bridge / TLV audio handling.

The goal of IPSC3D is to provide:

- a direct DMR+/IPSC3 backend for AllTune2
- lightweight IPSC3 network handling
- fast talkgroup connection and switching behavior
- direct integration with the AllTune2 dashboard and status APIs
- clean local runtime handling without committing private node configuration

## Configuration and Private Data

Live configuration files must not be committed to the public repository.

In particular, this file should remain local to each installation:

```text
ipsc3/config/ipsc3d.ini
```

The public repository should include only safe example configuration files, such as:

```text
ipsc3/config/ipsc3d.ini.example
```

Users are responsible for setting their own callsign, DMR identity values, node values, IPSC3 server settings, security key, and local DVSwitch / Analog_Bridge settings.

## Attribution

IPSC3D and the AllTune2 IPSC3 integration were developed by Terry Claiborne through extended testing and iteration for the AllTune2 project.

Please preserve this notice and project attribution in redistributed copies or forks.
