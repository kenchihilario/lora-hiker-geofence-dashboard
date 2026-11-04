# Phone-to-ESP32 GPS link options (planning only)

No phone has been tested and no transport is implemented. All options below are **UNVERIFIED** on the Poco X8 Pro and Tecno Pova.

| Option | Possible arrangement | Status on both target phones |
|---|---|---|
| Design A · Bluetooth | A small Android app reads phone location and sends logical coordinates to an ESP32 over Bluetooth Low Energy. | UNVERIFIED; requires app, permission, BLE service design and firmware contract. |
| Design A · terminal | A ready-made Bluetooth serial terminal app sends manually entered or app-forwarded text to an ESP32 BLE UART-like service. | UNVERIFIED; service compatibility and data contract are unknown. |
| Design B · USB OTG serial | An Android serial terminal app connects by USB-OTG to a supported USB-to-UART adapter / ESP32 USB interface. | UNVERIFIED; OTG, cable, chipset and app compatibility are untested. |
| Browser · Web Bluetooth | A local browser page uses geolocation and Web Bluetooth to send coordinates to a BLE service. | UNVERIFIED; browser/API support and permissions are unknown. |
| Browser · Web Serial | A local browser page uses geolocation and Web Serial with a USB serial device. | UNVERIFIED; browser support, secure-context behavior, OTG and serial contract are unknown. |

The firmware team must specify the actual logical handoff and transport before implementation. This project defines no binary packet length or USB serial framing.
