# TGIFD Copyright and Project Notice

## AllTune2 TGIFD / TGIF Implementation

TGIFD is the AllTune2 TGIF backend implementation developed for this project to provide a direct, lightweight TGIF network path for AllTune2.

TGIFD replaces the older HBLink-based TGIF backend in AllTune2. It is intended to be maintained as part of the AllTune2 project tree under:

    tgif/

The TGIFD implementation includes the TGIF network client, TLV / Analog_Bridge audio bridge logic, helper script integration, runtime status handling, configuration examples, and AllTune2 installer support needed to make TGIF work from the AllTune2 web interface.

## Copyright

Copyright (c) 2026 Terry Claiborne and the AllTune2 project.

Contact: kc3kmv@yahoo.com

All rights are reserved unless a separate license file in this repository states otherwise.

This notice documents that the TGIFD implementation and the AllTune2 TGIF integration are part of the AllTune2 project. It does not grant permission to remove attribution, rebrand the TGIFD implementation as a separate project, or redistribute it outside the terms provided by the AllTune2 project owner.

## Project Scope

TGIFD is designed for AllTune2 and DVSwitch-style systems using Analog_Bridge / TLV audio handling.

The goal of TGIFD is to provide:

- a lower-overhead TGIF backend for AllTune2
- reduced disk/log growth compared with the older HBLink-based TGIF path
- fast TGIF connect and talkgroup switching behavior
- direct integration with the AllTune2 dashboard and status APIs
- clean local runtime handling without committing private node credentials

## Configuration and Private Data

Live configuration files must not be committed to the public repository.

In particular, this file should remain local to each installation:

    tgif/config/tgifd.ini

The public repository should include only safe example configuration files, such as:

    tgif/config/tgifd.ini.example

Users are responsible for setting their own TGIF security key, DMR identity values, node values, and local DVSwitch / Analog_Bridge settings.

## HBLink Replacement Notice

TGIFD is intended to replace the previous AllTune2 TGIF/HBLink backend.

Future AllTune2 setup and update logic should install TGIFD directly for new users and migrate existing TGIF/HBLink users to TGIFD without affecting unrelated AllTune2 features such as BrandMeister, STFU, YSF, AllStarLink, EchoLink, favorites, authentication, or the dashboard UI.

## Attribution

TGIFD and the AllTune2 TGIF integration were developed by Terry Claiborne through extended testing and iteration for the AllTune2 project.

Please preserve this notice and project attribution in redistributed copies or forks.
