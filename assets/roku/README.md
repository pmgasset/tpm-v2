# Jordan View Roku Application

The `jordan-view` directory contains a production-ready Roku SceneGraph application that surfaces current guest information, reservation details, curated media, and a one-tap checkout action powered by the Guest Management System plugin.

## Features

- Authenticated integration with the plugin's `/gms/v1/roku` REST endpoints
- Beautiful hero layout that showcases WordPress media tagged for each property
- Real-time rendering of the current guest name, stay summary, door code, and booking reference
- Upcoming reservation teaser to help the operations team prepare for the next arrival
- Secure checkout workflow that transitions the reservation to `completed`
- Manual refresh support (press the Roku *Options* or *Rewind* button)

## File Structure

```
assets/roku/jordan-view/
├── components/
│   ├── tasks/ApiRequestTask.(xml|brs)
│   └── views/JordanViewScene.(xml|brs)
├── config/app-config.sample.json
├── images/icon.png
├── manifest
└── source/
    ├── AppConfig.brs
    └── main.brs
```

## Configuring the App

1. Copy `config/app-config.sample.json` to `config/app-config.json` and update the values:
   - `apiBaseUrl` – usually `https://<your-domain>/wp-json/gms/v1`
   - `rokuToken` – the shared secret configured under **Guest Management → Settings → Integrations → Roku API Token**
   - Optionally set `propertyId`, `propertyName`, or `mediaTag` to scope content per TV
   - `title` – override the on-screen heading (defaults to `Jordan View`)
2. Replace the placeholder text file in `images/icon.png` with branded 540x405 PNG channel art before packaging the build. The repository intentionally ships a text placeholder so it can be stored without binary assets.

## WordPress Requirements

- WordPress 6.0+
- Guest Management System plugin (this repository) with the Roku integration enabled
- Ensure `gms_roku_api_token` is populated in the plugin settings (a secure token is generated automatically on activation)
- Tag media assets in the WordPress Media Library using the configured Roku gallery prefix (default: `roku`).
  - Example: tag an image with `roku-main-house` to display it when `propertyId` is `main-house`.

## Building & Side-loading

1. Zip the contents of `assets/roku/jordan-view` (do not include the parent folder) using the `.pkg` packaging instructions provided by Roku.
2. Enable developer mode on the Roku device (`Home` x3, `Up` x2, `Right`, `Left`, `Right`, `Left`, `Right`).
3. Visit `http://<roku-ip-address>` in a browser, upload the package, and provide the Roku developer password.
4. After installation, open **Jordan View**. Use the Roku *Options* button to refresh if you update reservations or media during testing.

## API Overview

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/wp-json/gms/v1/roku/dashboard` | GET | Returns current and upcoming reservations, branding metadata, and curated media. |
| `/wp-json/gms/v1/roku/reservations/<id>/checkout` | POST | Marks the reservation as completed and returns the updated record. |

Both endpoints require the `X-Roku-Token` header to match the configured Roku API token. The checkout endpoint returns the latest reservation payload so the UI can update without an additional fetch.

## Refresh & Error Handling

- The app shows a spinner while the dashboard is loading or during checkout.
- API or network errors display a purple banner at the bottom of the screen with diagnostic details.
- The checkout button is hidden until the reservation is eligible for checkout (after check-in and while the status is not `completed`/`cancelled`).

## Extending

- Hook into `gms_roku_reservation_checked_out` to trigger housekeeping automations.
- Customize the REST responses via the new helper methods in `GMS_Roku_Integration` if you need additional fields on-screen.
- Swap the `RowList` for a `MarkupGrid` if you prefer a tiled gallery layout.

