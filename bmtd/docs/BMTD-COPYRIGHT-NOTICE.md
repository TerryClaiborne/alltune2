# BMTD Copyright and Project Notice

## AllTune2 BMTD / BrandMeister Implementation

BMTD is the AllTune2 BrandMeister backend implementation developed for this project to provide a direct, lightweight BrandMeister network path for AllTune2.

BMTD replaces the older STFU-based BrandMeister backend in AllTune2. It is intended to be maintained as part of the AllTune2 project tree under:

```text
bmtd/
```

The BMTD implementation includes the BrandMeister network client, Rewind protocol handling, TLV / Analog_Bridge audio bridge logic, helper script integration, runtime status handling, configuration examples, and AllTune2 installer support needed to make BrandMeister work from the AllTune2 web interface.

## Copyright

Copyright (c) 2026 Terry Claiborne and the AllTune2 project.

Contact: kc3kmv@yahoo.com

All rights are reserved unless a separate license file in this repository states otherwise.

This notice documents that the BMTD implementation and the AllTune2 BrandMeister integration are part of the AllTune2 project. It does not grant permission to remove attribution, rebrand the BMTD implementation as a separate project, or redistribute it outside the terms provided by the AllTune2 project owner.

## Project Scope

BMTD is designed for AllTune2 and DVSwitch-style systems using Analog_Bridge / TLV audio handling.

The goal of BMTD is to provide:

- a lower-overhead BrandMeister backend for AllTune2
- a replacement for the older STFU-based BrandMeister path
- fast BrandMeister connect and talkgroup switching behavior
- direct integration with the AllTune2 dashboard and status APIs
- clean local runtime handling without committing private node credentials

## Configuration and Private Data

Live configuration files must not be committed to the public repository.

In particular, this file should remain local to each installation:

```text
bmtd/config/bmtd.ini
```

The public repository should include only safe example configuration files, such as:

```text
bmtd/config/bmtd.ini.example
```

Users are responsible for setting their own BrandMeister password, DMR identity values, node values, and local DVSwitch / Analog_Bridge settings.

## STFU Replacement Notice

BMTD is intended to replace the previous AllTune2 BrandMeister/STFU backend.

Future AllTune2 setup and update logic should install BMTD directly for new users and migrate existing BrandMeister/STFU users to BMTD without affecting unrelated AllTune2 features such as TGIFD, YSF, AllStarLink, EchoLink, favorites, authentication, or the dashboard UI.

## Attribution

BMTD and the AllTune2 BrandMeister integration were developed by Terry Claiborne through extended testing and iteration for the AllTune2 project.

Please preserve this notice and project attribution in redistributed copies or forks.
