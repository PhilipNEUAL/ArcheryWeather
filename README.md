# Archery Weather

This is an [IANSEO](https://www.ianseo.net/) module for recording environmental data collected from archery competitions in the UK.
This project grew directly out of the [ResultsPack](https://github.com/PhilipNEUAL/ResultsPack) module, in trying to provide hyper-local accurate weather data from a weather unit on the event field. It has since well outgrown its place.
In its current iteration, it retrieves observations from the Tempest cloud service, stores them in the IANSEO database, and produces session summaries and graphs.

## Development
Development testing has used Windows 11, XAMPP, and PHP 8.2.

## Requirements
- A working copy of [IANSEO](https://www.ianseo.net/).
- A tempest station with personal access token.

## Installation

1. Back up your IANSEO database before installing or updating custom modules.
2. Download the installation ZIP attached to the release.
3. Extract it and copy the `ArcheryWeather` folder into IANSEO's `Modules/Custom` directory.

The final layout should be:

```text
Modules/
  Custom/
    ArcheryWeather/
      index.php
      menu.php
      Lib/
      js/
```

The folder name must be named `ArcheryWeather`. Be careful: GitHub archives may extract with the branch attached, such as `ArcheryWeather-main`.

Open the module's `index.php` to initialise the tables. For a local installation with IANSEO under `/ianseo`, the address is:

```text
http://localhost/ianseo/Modules/Custom/ArcheryWeather/
```

After successful setup the module opens to the session list. Refresh the browser to see the ArcheryWeather menu. 

## Tempest configuration

Create `WeatherConfig.local.php` in the ArcheryWeather folder, beside `index.php`, containing:

```php
<?php

return array(
    'personal_access_token' => 'YOUR_TEMPEST_PERSONAL_ACCESS_TOKEN',
    'timezone' => 'Europe/London',
);
```

Use your own Tempest personal access token. DO NOT distribute this file with the token included. The repository's `.gitignore` excludes it.

Use an IANA timezone such as `Europe/London`, rather than a fixed abbreviation such as `BST`. This allows session displays to follow daylight-saving changes.

## Starting and collecting a session

1. Open **ArcheryWeather → New**.
2. Select an IANSEO competition and Tempest station of choice.
3. Check that the station is online and reporting.
4. Choose **Real** for an actual event or **Test** for development or practice (it is useful to have a test ianseo session for testing purposes).
5. Record the shooting direction and relevant station-position metadata if practical* - this can be added later if not.
6. Start the session and open the linked viewer.

*Positive forward offset means towards the targets; positive lateral offset means right of the field centre line.

### Automatic collection

When the session viewer page is open, the module automatically retrieves observations, but only while the tab is visible. It waits approximately one minute between completed checks, and refreshes the summary and graphs when new observations are added.

The live station status display shows the latest observation available from Tempest, its age when checked, and the check time.

Automatic collection stops when the tab is closed.

The first collection requests the session's elapsed window. Later automatic collections request a five-minute overlap before the latest stored reading, plus any newer readings. The overlap catches recent delayed readings, and final retrieval can fill other gaps. 

The **Retrieve observations now** button requests an additional check. 

### Ending a session

Open the session's details page and use **End session**. The module records the end time before attempting retrieval across the full session window.

If the module fails to retrieve details, check tempest is on and connected, then use **Retrieve observations from Tempest** on the completed session's details page to retry.

## Reviewing session data

The session viewer provides:

- A short weather summary with environmental statistics (this can be copied into ResultsPack, or ResultsPack can automaticallyretrieve this short statement).
- Wind-speed, wind-direction, and other environmental graphs which can be downloaded as SVG or PNG.
- Links to further session details, including metadata, data quality, minute-by-minute observations, a record of any recorded judge decisions, and metadata correction history.

Original wind measurements are recorded and stored in miles per hour, but the displayed unit is in metres per second, as per scientific convention (thanks Nye). The CSV export includes both units.

Session calculations use observations inside the current session window. JSON export preserves all stored observations, including records outside a subsequently corrected window.

### Corrections and judge decisions

Sometimes it is necessary to adjust the start or end time of a session. Session timing corrections retain a complete history of originally recorded and new times with a reason. Direction-reference corrections also retain their audit history.

More rarely it is necessary to make a record of a decision made by a judge, for instance to delay the start of a shoot, suspend shooting, or abort the shoot due to weather. Judge decisions can be recorded during an open session or entered retrospectively at a specified time inside the session window. Recording a decision to suspend or abort the event does not end the session. The history distinguishes the event time from the timestamp when the entry was saved. Existing decisions cannot currently be edited (working on it).

Current station metadata and research status can be changed on the details page. These edits do not currently retain previous values in a separate audit history. Describe changes to physical deployment clearly in the notes.

## Sharing and backups

Open **ArcheryWeather → Import** to access the Import / Export page.

- **JSON** contains the session metadata, observations, judge decisions, and correction histories needed for session transfer.
- Selecting multiple sessions produces a ZIP containing one file per session.
- **CSV** provides an observation table for analysis; it is not a complete restorable session backup and cannot currently be used as an import format.

Import accepts JSON session packages and ZIPs of those packages, including compatible ResultsPack weather-session exports. Before importing, the module offers the opportunity to preview the file, review matches, and select a local IANSEO competition for each new session.

The importer validates the whole upload before saving and saves an import batch in one database transaction. Invalid packages are rejected. ZIP imports currently allow up to 20 sessions and 20 MiB of total uncompressed JSON; individual JSON uploads have the same 20 MiB limit.

Keep JSON exports and normal IANSEO database backups for data you need to retain.

## Research status and cleanup

Sessions can be marked **Real**, or **Test** (or, currently, "Excluded" - I added that for reasons known only to me and forgotten by everybody who once knew them).

**ArcheryWeather → Delete** lists only completed Test or Excluded sessions. Cleanup includes a review step and explicit confirmation, and removes the selected sessions together with their observations, events, and correction histories.

Real sessions are protected from this deletion. It is possible to change a Real session to a Test session in order to delete it, but this requires additional confirmation. Back up any files you want to retain before deleting them.

## Optional ResultsPack integration

ResultsPack v1.0.3 includes an optional connector to ArcheryWeather. Install the modules beside each other:

```text
Modules/Custom/ResultsPack/
Modules/Custom/ArcheryWeather/
```

Choose a session and use **Use ArcheryWeather summary** to copy its concise summary into the editable environmental notes and populate humidity.

The copied text is a snapshot that can be edited before generating the results document. It does not update automatically. ResultsPack still supports manual weather entry when ArcheryWeather is absent.

## Updating or removing the module

Retain `WeatherConfig.local.php` and back up the IANSEO database before updating.

Removing the module folder does not remove its database records. ArcheryWeather stores its data in:

```text
CustomArcheryWeatherSessions
CustomArcheryWeatherObservations
CustomArcheryWeatherEvents
CustomArcheryWeatherTimingCorrections
CustomArcheryWeatherDirectionCorrections
```

Do not drop these tables unless their data is backed up or no longer required. The module does not modify IANSEO scoring results.

## Troubleshooting

## Development

Developed and maintained by Philip Taylor. Bug/gripe reports are welcome. Please include the relevant page, steps to reproduce, and error message, as well as any operating system versions. DO NOT share the file containing the personal access token.

Some internal helpers retain the `resultspack_weather_` prefix from the original project. This is leftover during the transition and does not require the ResultsPack module to be installed!
