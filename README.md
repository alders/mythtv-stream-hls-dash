# Description

HTTP Live streaming support for [MythTV](https://www.mythtv.org).

Why:

- Although support was added for HTTP Live Streaming (`HLS`) to
  [MythTV](https://www.mythtv.org) in v0.25 it is not yet usable.
- [MythWeb](https://www.mythtv.org/wiki/MythWeb) is no longer actively
  developed and is based on Flash technology from the days of yore.
- HTML5 support for
  [WebFrontend](https://www.mythtv.org/wiki/WebFrontend) is work in
  progress.
- The new [Web Application](https://www.mythtv.org/wiki/Web_Application)
  (MythTV v34) interface does not yet provide HTTP streaming support.
- An attempt to provide streaming support was made in github project
  [MythTV stream mpeg
  DASH](https://github.com/thecount2a/mythtv-stream-mpeg-dash).
- All lack support for `HLS`, Adaptive Bitrate Streaming (`ABR`), live
  recording, live broadcast, subtitles, etcetera.

What:

- Support for HTTP based streaming (serving) of HDHomeRun, MythTV and
  MythVideo content.
- Support for device independent viewing: web browser—mobile, desktop,
  tablet, etc.
- Support for less reliable networks (e.g. cell phone browser).
- Support for live tv, live recording, video and recorded content.
- Support for offline viewing.
- Support for MythTV cutlist (commercial cut) created using
  Mythfrontend.

How:

- Encode MythTV recordings and MythVideo content providing playlist
  types `live`, `event` and `VOD`.
- MPEG-DASH and HLS with fragmented MP4 (fMP4) makes both compatible,
  therefore only the manifest file (playlist) is different.
- Transcode to `MP4` for offline playback on mobile devices.
- Video is codified in H.264 format and audio in AAC.
- HW accelerated support for VAAPI (other hw acceleration options are
  untested).
- Simple PHP browser UI.
- Transcode videos to user defined (UI select dropdown list) renditions
  for adaptive playback.
- Optionally a ramdisk can be used for in memory handling of playlist
  type `live`.
- Optionally shutdown lock can be used to prevent MythWelcome from
  shutting down.

# Installation

The installation below is based on Fedora and Apache as web server.
Adapting the code to your distribution / web server is left as an
exercise to the user.

## Dependency

- MythTV (for looking up the recording / video name, tv channels info
  and for using commercial cut)
  - Any version up to and including version v0.34 (the latter is
    required for MythVideo support via [Web
    Application](https://www.mythtv.org/wiki/Web_Application))
- FFmpeg (for encoding)[^1]
  - FFmpeg version 5.1.3
- GNU screen
  - This is to allow monitoring of encoding and to support background
    processes launched by the web-facing PHP script.
  - version 4.9.0
- Shaka player
  - This is used as the built-in Javascript-based browser player.
  - version 4.3.6
- Mediainfo
  - Displays technical information about media files.
  - version 23.09
- HDHomeRun
  - Firmware Version 20230713

Note: take security measures in case you decide to open your web server
to the internet. Use at your own risk.

## Install dependencies

``` shell
sudo dnf install mythtv ffmpeg screen mediainfo inotify-tools hdhomerun-devel sed
```

## Create apache user

Create a web content owner[^2]:

``` shell
sudo useradd -d /var/www/ -g apache -M -N -s /sbin/nologin apache
sudo chown -R apache:apache /var/www/html
sudo chmod -R 755 /var/www/html
```

## Configure sudo

The backend code generates `bash` scripts. The commands in the scripts
are run as user `apache` using `sudo`:

``` shell
sudo visudo -f /etc/sudoers.d/apache
[mythtv@nuc1 ~]$ sudo cat /etc/sudoers.d/apache
apache ALL=(ALL) NOPASSWD: /usr/bin/hdhomerun_config, /usr/bin/ffmpeg, /usr/bin/realpath, /usr/bin/sed, /usr/bin/tail, /usr/bin/chmod, /usr/bin/mediainfo, /usr/bin/screen, /usr/bin/echo, /usr/bin/mkdir, /usr/bin/bash, /usr/bin/awk
```

## Install Shaka-player

``` shell
git clone https://github.com/shaka-project/shaka-player.git
cd shaka-player
python build/all.py
sudo mkdir /var/www/html/dist
sudo chown apache:apache /var/www/html/dist
sudo -uapache rsync -avh dist/ /var/www/html/dist/
```

## Install mythtv-stream-hls-dash

``` shell
git clone https://github.com/alders/mythtv-stream-hls-dash.git
sudo mkdir /var/www/html/mythtv-stream-hls-dash
sudo chown apache:apache /var/www/html/mythtv-stream-hls-dash
sudo -uapache rsync -avnh --exclude='.git/' mythtv-stream-hls-dash/*.php /var/www/html/mythtv-stream-hls-dash/
```

## Patch MythWeb

Optional step, modify 2 lines of MythWeb code to change ASX Stream
button on the "Recorded Programs" page to `Stream HLS DASH` button.

``` shell
diff --git a/modules/tv/tmpl/default/recorded.php b/modules/tv/tmpl/default/recorded.php
index 8502305b..7bf3db0b 100644
--- a/modules/tv/tmpl/default/recorded.php
+++ b/modules/tv/tmpl/default/recorded.php
@@ -158,8 +158,8 @@ EOM;
             echo ' -noimg">';
 ?>
         <a class="x-download"
-            href="<?php echo video_url($show, true) ?>" title="<?php echo t('ASX Stream'); ?>"
-            ><img height="24" width="24" src="<?php echo skin_url ?>/img/play_sm.png" alt="<?php echo t('ASX Stream'); ?>"></a>
+            target="_blank" href="/mythtv-stream-hls-dash/index.php?filename=<?php echo $show->chanid."_".gmdate('YmdHis', $show->recstartts) ?>" title="<?php echo 'Stream HLS DASH'; ?>"
+            ><img height="24" width="24" src="<?php echo skin_url ?>/img/play_sm.png" alt="<?php echo 'Stream HLS DASH'; ?>"></a>
         <a class="x-download"
             href="<?php echo $show->url ?>" title="<?php echo t('Direct Download'); ?>"
             ><img height="24" width="24" src="<?php echo skin_url ?>/img/video_sm.png" alt="<?php echo t('Direct Download'); ?>"></a>
```

## Patch Web Application

The use of [Web
Application](https://www.mythtv.org/wiki/Web_Application) requires
[MythTV installation from
sources](https://www.mythtv.org/wiki/Build_from_Source).

Optionally change a few lines in the [Web
Application](https://www.mythtv.org/wiki/Web_Application)[^3] to allow
recording and / or video and / or live tv selection from your browser.
Replace `mydomain` in the patches below to point to your combined web
server / `Mythbackend` address.

To allow web browser recording selection for playback on any device:

``` shell
diff --git a/mythtv/html/backend/src/app/dashboard/recordings/recordings.component.html b/mythtv/html/backend/src/app/dashboard/recordings/recordings.component.html
index 4618e41aa8..8bae11e03a 100644
--- a/mythtv/html/backend/src/app/dashboard/recordings/recordings.component.html
+++ b/mythtv/html/backend/src/app/dashboard/recordings/recordings.component.html
@@ -76,7 +76,8 @@
                     <td style="flex-basis: 12%" class="p-1 overflow-hidden">
                         <i class="pi pi-exclamation-triangle p-1" *ngIf="program.VideoPropNames.indexOf('DAMAGED') > -1"
                             pTooltip="{{ 'dashboard.recordings.damaged' | translate }}" tooltipPosition="top"></i>
-                        {{program.Title}}
+                       <a href="{{URLencode('http://mydomain/mythtv-stream-hls-dash/index.php?filename=' + program.Recording.FileName.split('.').slice(0, -1).join('.'))}}" target="_blank">{{program.Title}}</a></td>
+
                     </td>
                     <td style="flex-basis: 2%" class="p-1">
                         <i class="pi pi-eye" *ngIf="program.ProgramFlagNames.indexOf('WATCHED') > -1"
```

To allow web browser video selection for playback on any device.

``` shell
diff --git a/mythtv/html/backend/src/app/dashboard/videos/videos.component.html b/mythtv/html/backend/src/app/dashboard/videos/videos.component.html
index 2d75b5e0ab..42abea28ac 100644
--- a/mythtv/html/backend/src/app/dashboard/videos/videos.component.html
+++ b/mythtv/html/backend/src/app/dashboard/videos/videos.component.html
@@ -68,7 +68,7 @@
                                 (click)="onDirectory(video.Title)" label="{{video.Title}}"></button>
                         </div>
                         <ng-template #title>
-                            {{video.Title}}
+                            <a href="{{URLencode('http://mydomain/mythtv-stream-hls-dash/index.php?videoid=' + video.Id)}}" target="_blank">{{video.Title}}</a>
                         </ng-template>
                     </td>
                     <td style="flex-basis: 3%" class="p-1">
```

To allow web browser tv channel selection for playback on any device.

``` shell
diff --git a/mythtv/html/backend/src/app/guide/components/channelicon/channelicon.component.html b/mythtv/html/backend/src/app/guide/components/channelicon/channelicon.component.html
index 44abe96fea..c17429ef6c 100644
--- a/mythtv/html/backend/src/app/guide/components/channelicon/channelicon.component.html
+++ b/mythtv/html/backend/src/app/guide/components/channelicon/channelicon.component.html
@@ -4,6 +4,6 @@
         <ng-template #nullIcon><img height="0" width="0"></ng-template>
     </div>
     <div class="channelText">
-        <span>{{ channel.ChanNum}} {{ channel.CallSign }}</span>
+        <span><a href="{{URLencode('http://192.168.1.29/mythtv-stream-hls-dash/hdhomerunstream.php?quality[]=high480&hw=h264&channel=' + channel.CallSign + '&do=Watch+TV')}}" target="_blank">{{channel.ChanNum}} {{ channel.CallSign }}</a></span>
     </div>
</div>
diff --git a/mythtv/html/backend/src/app/guide/components/channelicon/channelicon.component.ts b/mythtv/html/backend/src/app/guide/components/channelicon/channelicon.component.ts
index 97ae71efa8..f088012f94 100644
--- a/mythtv/html/backend/src/app/guide/components/channelicon/channelicon.component.ts
+++ b/mythtv/html/backend/src/app/guide/components/channelicon/channelicon.component.ts
@@ -16,4 +16,8 @@ export class ChannelIconComponent implements OnInit {
   ngOnInit(): void {
   }

+  URLencode(x: string): string {
+      let trimmed = x.replace(/\s+/g, '');
+      return encodeURI(trimmed);
+  }
 }
```

To apply these optional changes run the npm build script and install the
web application:

``` shell
cd mythtv/mythtv/html/backend/
npm run-script build
cd ..
sudo make install
```

## Allow JavaScript

Allow JavaScript in your browser.

# HTTP streaming

## Example

### User interface

Figure 1 shows the user interface of `mythtv-stream-hls-dash` after
selecting a recording in MythWeb or the new Web_Application[^4].

**Figure 1:** *User interface.*

<figure id="user-interface" alt="User selection" title="User selection"
data-align="right" width="350px">
<img src="screenshots/user-selection.png" />
<figcaption>User interface</figcaption>
</figure>

User interface options from top to bottom:

- Select an available recording from the list box[^5].
- Select the `ABR` renditions from the select dropdown list box.
- Select the HW acceleration from the list box[^6].
- Select if the `Cutlist` should be used using the list box[^7].
- Select using the checkbox if `Subtitles` should be created[^8].
- Select using the checkboxes if playlist type `live` or `event` should
  be used[^9].
- Select using the checkbox if playlist type `VOD` should be used.
- Select using the checkbox if a `MP4` file should be created.
- Press **Encode Video** when you are satisfied with your choices to
  start encoding.

The selections shown in Figure 1 are used in the descriptions below.

### Adaptive Bitrate Streaming

Figure 2 shows in more detail the user interface (phone interface) to
select the renditions for Adaptive Bitrate Streaming (ABR). Use
Ctrl-Click (Windows), Command-Click (Apple) to select the renditions.

**Figure 2:** *Adaptive Bitrate UI.*

<figure id="adaptive-bitrate-ui" alt="Remuxing video"
title="Remuxing video" data-align="right" width="350px">
<img src="screenshots/abr.png" />
<figcaption>Adaptive Bitrate UI</figcaption>
</figure>

### Remuxing

This step is only shown when [commercials are manually
cut](https://www.mythtv.org/wiki/Editing_Recordings) in `mythfrontend`.
Figure 3 shows the user interface while remuxing. Because
`Cut Commercials` was selected in Figure 1, the video is remuxed to an
`MP4` container.

**Figure 3:** *Remuxing UI.*

<figure id="remuxing-video" alt="Remuxing video" title="Remuxing video"
data-align="right" width="350px">
<img src="screenshots/remuxing-video.png" />
<figcaption>Remuxing UI</figcaption>
</figure>

Three buttons are shown below the available recording list box.

The first button `Delete Video Files` basically does what is says[^10].

The second status button displays a dynamic message. Figure 3 shows the
`Remuxing
Video` percentage.

The third button `Shutdown Lock` can be used to prevent
[MythWelcome](https://www.mythtv.org/wiki/index.php/Mythwelcome) from
shutting down. In combination with Wake-On-Lan (WOL) configured on your
`mythbackend` machine this allows one to have full control from your
browser.

### Generating video

Figure 4 shows the user interface while encoding the video.

**Figure 4:** *Generating video.*

<figure id="generating-video" alt="Generating video"
title="Generating video" data-align="right" width="350px">
<img src="screenshots/encoding-video.png" />
<figcaption>Generating video.</figcaption>
</figure>

Progress of the encoding is shown on the status button as a percentage
and the time of the video available. When there is about 6 seconds of
video available the player automatically tries to load the video[^11].

At the right hand side of the `Shutdown Lock` buttons appear
dynamically, when files become available on disk. In Figure 4 this is
the case for `HLS event` and `HLS VOD`. The video should load
automagically within 30 seconds. If this does not happen, select either
of the buttons to start playing. As a last resort one could reload the
web page.

Old devices not supporting the Shaka video player of the UI, may still
be able to play media through the buttons provided. The http links can
also be copied and used in your favorite app.

### Status button

Figure 5 shows what happens in case the status button is selected. This
will trigger a popup message box with a detailed view of the steps
involved and the status thereof.

**Figure 5:** *Status UI.*

<figure id="status" alt="Status" title="Status" data-align="right"
width="350px">
<img src="screenshots/status-button.png" />
<figcaption>Status UI</figcaption>
</figure>

### User interface after encoding

Figure 6 shows the interface after encoding is done.

**Figure 6:** *User interface after encoding.*

![](screenshots/encoding-finished.png)

Two additional buttons appeared in Figure 6 for `Cleanup Video Files`
[^12] and `Download MP4`.

Since both playlists `HLS event` and `HLS VOD` basically provide similar
user experience for HLS one may decide to remove the playlist
`HLS event` files to reduce disk space. This is exactly what the
`Cleanup Video Files` button does.

The UI also shows a `Download MP4` link as was requested in Figure 1.
The latter is only visible when the encoding has finished and optionally
subtitles are mixed in.

## Features

Playlist type (and `MP4)` support for live broadcast, video and recorded
video are shown in table 1. `DASH` is only supported by `VOD`, whereas
`HLS` (and `ABR`) is supported by all playlist types. Subtitles are
supported by all.

**Table 1:** *Playlist and MP4 support for live broadcast and recorded
video.*

| Playlist | HLS | DASH | subtitle[^13] | subtitle[^14] | ABR |
|----------|-----|------|---------------|---------------|-----|
| live     | ✅  |      | ✅            |               | ✅  |
| event    | ✅  |      | ✅            |               | ✅  |
| VOD      | ✅  | ✅   | ✅            |               | ✅  |
| MP4      |     |      |               | ✅            |     |

Feature support for live broadcast and recorded video.

All possible UI combinations of playlist types and MP4 that can be
chosen by the user are shown in table 2[^15].

**Table 2:** *All possible UI combinations of playlist types and MP4.*

| live | event | VOD | MP4 |
|------|-------|-----|-----|
| ✅   |       |     |     |
| ✅   |       | ✅  |     |
| ✅   |       |     | ✅  |
| ✅   |       | ✅  | ✅  |
|      | ✅    |     |     |
|      | ✅    | ✅  |     |
|      | ✅    |     | ✅  |
|      | ✅    | ✅  | ✅  |
|      |       | ✅  |     |
|      |       |     | ✅  |
|      |       | ✅  | ✅  |

All possible combinations of playlist types and MP4.

Table 3, 4 and 5 shows feature support of the Safari built-in m3u8
player and Shaka player while encoding a set of random renditions:
`720p high`, `480p normal`, `360p low`, and `240p low`. As is shown
feature support varies. None of them provides the desired combination
i.e. allowing one to manually select the desired video rendition and
audio rendition (at least for testing purposes). Hopefully the players
really do provide the best possible bitrate for the network
"*automagically*".

**Table 3:** *Safari m3u8 player UI playlist support during Live
Broadcasting (while encoding).*

| Playlist | Progress bar | Subtitles | Resolution | Language                             |
|----------|--------------|-----------|------------|--------------------------------------|
| live     | 🔴           | Dutch     | 🔴         | (Dutch (audio_0)),..,Dutch (audio_2) |
| event    | 🔴           | Dutch     | 🔴         | (Dutch (audio_0)),..,Dutch (audio_2) |
| VOD      | 🔴           | Dutch     | 🔴         | (Dutch (audio_4)),..,Dutch (audio_6) |
| MP4      | ✅           |           | 🔴         | 🔴                                   |

Safari m3u8 player.

**Table 4:** *Shaka player (configuration ("useNativeHlsOnSafari" :
true)) UI playlist support during Live Broadcasting (while encoding).*

| Playlist | Progress bar | Captions   | Resolution   | Language                                  | Quality          |
|----------|--------------|------------|--------------|-------------------------------------------|------------------|
| live     | ✅ [^16]     | Nederlands | Auto (nullp) | Nederlands                                | 🔴               |
| event    | ✅           | Nederlands | Auto (nullp) | Nederlands                                | 🔴               |
| VOD      | ✅           | Nederlands | Auto (nullp) | Nederlands,Nederlands (2 out of 3 tracks) | 🔴               |
| MP4      | ✅           |            | 🔴           | Nederlands                                | Auto (0 kbits/s) |

Shaka player configuration `("useNativeHlsOnSafari" : true)` playlist
support.

**Table 5:** *Safari Player (configuration ("useNativeHlsOnSafari" :
false)) UI playlist support during Live Broadcasting (while encoding).*

| Playlist | Progress bar | Captions | Resolution    | Language   | Quality          |
|----------|--------------|----------|---------------|------------|------------------|
| live     | 🔴           | ✅ (off) | 240p          | 🔴         | 🔴               |
| event    | ✅           | ✅ (off) | 720p,.., 240p | Nederlands | 🔴               |
| VOD      | 🔴           | ✅ (off) | 720p,.., 240p | Nederlands | 🔴               |
| MP4      | ✅           |          | 🔴            | Nederlands | Auto (0 kbits/s) |

Shaka Player `("useNativeHlsOnSafari" : false)` playlist support.

## Generated script

After pressing the `Encode Video` in Figure 1 a `bash` shell script is
generated. For illustration purposes the code for the running example is
shown in separate code blocks below.

### Remuxing

The user in Figure 1 selected `Cut Commercials`. This requires the input
video to be remuxed to a `MP4` container as shown in the user interface
of Figure 3. The code block below shows in detail how this is done.

An `MP4` container allows FFmpeg to use the `concat demuxer` later in
the script[^17].

``` shell
cd /var/www/html/hls/10100_20231101212100
/usr/bin/sudo /usr/bin/screen -S 10100_20231101212100_remux -dm /usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: remux start > /var/www/html/hls/10100_20231101212100/status.txt;
/usr/bin/sudo -uapache /usr/bin/ffmpeg \
          -y \                                                                                         # Overwrite without asking
          -hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi \              # Use VAAPI Hardware acceleration
          -txt_format text -txt_page 888 \                                                             # extract subtitles from dvb_teletext
          -fix_sub_duration \                                                                          # avoid overlap of subtitles
          -i /mnt/mythtv2/store//10100_20231101212100.ts \                                             # input file recorded with HDHomeRun
          -c copy \                                                                                    # use encoder copy for video and audio
          -c:s mov_text \                                                                              # set subtitle codec to mov_text
          /var/www/html/hls/10100_20231101212100/video.mp4 && \                                        # output file in mp4 format
/usr/bin/echo `date`: remux finish success >> /var/www/html/hls/10100_20231101212100/status.txt || \
/usr/bin/echo `date`: remux finish failed >> /var/www/html/hls/10100_20231101212100/status.txt'
while [ ! "`/usr/bin/cat /var/www/html/hls/10100_20231101212100/status.txt | /usr/bin/grep 'remux finish success'`" ] ; \
do \
    sleep 1; \
done
```

### Adapt playlist `master_event.m3u8` file

Adapt the playlist `master_event.m3u8` as soon as the file is created by
FFmpeg some time in the future. This allows the handling of subtitles
and the player to start at the beginning of the video:

``` shell
(while [ ! -f "/var/www/html/hls/10100_20231101212100/master_event.m3u8" ] ;
 do
        /usr/bin/inotifywait -e close_write --include "master_event.m3u8"  /var/www/html/hls/10100_20231101212100;
 done;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\1\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="Dutch",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI="sub_0_vtt.m3u8",LANGUAGE="dut"/' /var/www/html/hls/10100_20231101212100/master_event.m3u8;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\1\n#EXT-X-START:TIME-OFFSET=0/' /var/www/html/hls/10100_20231101212100/master_event.m3u8;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\1,SUBTITLES="subtitles"/'  /var/www/html/hls/10100_20231101212100/master_event.m3u8; /usr/bin/sudo -uapache /usr/bin/sudo sed -r '/(#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+\,CODECS)/{N;d;}' -i /var/www/html/hls/10100_20231101212100/master_event.m3u8;) &
```

### Adapt playlist **master_vod.m3u8** file

Adapt the playlist `master_vod.m3u8` file as soon as the file is created
by FFmpeg some time in the future. This allows the handling of subtitles
and the player to start at the beginning of the video. Additionally the
language of the audio is defined:

``` shell
(while [ ! -f "/var/www/html/vod/10100_20231101212100/master_vod.m3u8" ] ;
 do
        /usr/bin/inotifywait -e close_write --include "master_vod.m3u8" /var/www/html/vod/10100_20231101212100;
 done;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\1\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="Dutch",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI="sub_0_vtt.m3u8",LANGUAGE="dut"/' /var/www/html/vod/10100_20231101212100/master_vod.m3u8;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\1\n#EXT-X-START:TIME-OFFSET=0/' /var/www/html/vod/10100_20231101212100/master_vod.m3u8;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\1,SUBTITLES="subtitles"/' /var/www/html/vod/10100_20231101212100/master_vod.m3u8;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="group_A1")/\1,LANGUAGE="dut"/' /var/www/html/vod/10100_20231101212100/master_vod.m3u8;) &
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode start >> /var/www/html/hls/10100_20231101212100/status.txt';
```

### FFmpeg encoding

The major part of the encoding is done in one FFmpeg command utilizing
`filter_complex` and `tee` to the max. This code block starts the actual
encoding and waits until it is finished:

``` shell
/usr/bin/sudo -uapache /usr/bin/mkdir -p /var/www/html/hls/10100_20231101212100;
cd /var/www/html/hls/;
/usr/bin/sudo -uapache /usr/bin/ffmpeg \
    -fix_sub_duration \
    -txt_format text -txt_page 888 \
    -hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi \
     \
     \
    -f concat -async 1 -safe 0 -i /var/www/html/hls/10100_20231101212100/cutlist.txt \  # Use cutlist
    -progress 10100_20231101212100/progress-log.txt \ # Track progress of encoding
    -live_start_index 0 \                             # Segment index to start live streams at
    -force_key_frames "expr:gte(t,n_forced*2)" \      # Fixed key frame interval is needed to avoid variable segment duration.
    -tune film \                                      # use for high quality movie content; lowers deblocking
    -metadata title="De Avondshow met Arjen Lubach" \
    -filter_complex "[0:v]split=3[v1][v2][v3];[v1]scale_vaapi=w=1280:h=720[v1out];[v2]scale_vaapi=w=854:h=480[v2out];[v3]scale_vaapi=w=640:h=360[v3out]" \ # Resize A Video To Multiple Resolutions
    -map [v1out] -c:v:0 \        # Rendition 1
        h264_vaapi \             # Use H264 VAAPI (Video Acceleration API) hardware acceleration
        -b:v:0 3200k \           # Transcode Video 1 to a user selected bitrate
        -maxrate:v:0 3200k \     # Maximum bitrate
        -bufsize:v:0 1.5*3200k \ # Buffer size
        -crf 23 \                # Constant Rate Factor
        -preset veryslow \       #
        -g 48 \                  #
        -keyint_min 48 \         # Set minimum interval between IDR-frame
        -sc_threshold 0 \        # Sets the threshold for the scene change detection.
        -flags +global_header \  # Set global header in the bitstream.
    -map [v2out] -c:v:1 \        # Rendition 2
        h264_vaapi \             # Use H264 VAAPI (Video Acceleration API) hardware acceleration
        -b:v:1 1600k \           # Transcode Video 2 to a derived lower resolution based on a user selected bitrate
        -maxrate:v:1 1600k \     # Maximum bitrate
        -bufsize:v:1 1.5*1600k \ # Buffer size
        -crf 23 \                # Constant Rate Factor
        -preset veryslow \       #
        -g 48 \                  #
        -keyint_min 48 \         # Set minimum interval between IDR-frame
        -sc_threshold 0 \        # Sets the threshold for the scene change detection.
        -flags +global_header \  # Set global header in the bitstream.
    -map [v3out] -c:v:2 \        # Rendition 1
        h264_vaapi \             # Use H264 VAAPI (Video Acceleration API) hardware acceleration
        -b:v:2 900k \            # Transcode Video 3 to a derived lower resolution based on a user selected bitrate
        -maxrate:v:2 900k \      # Maximum bitrate
        -bufsize:v:2 1.5*900k \  # Buffer size
        -crf 23 \                # Constant Rate Factor
        -preset veryslow \       #
        -g 48 \                  #
        -keyint_min 48 \         # Set minimum interval between IDR-frame
        -sc_threshold 0 \        # Sets the threshold for the scene change detection.
        -flags +global_header \  # Set global header in the bitstream.
 \
    -map a:0 -c:a:0 aac -b:a:0 128k -ac 2 \
        -metadata:s:a:0 language=dut \
 \
    -map 0:s:0? -c:s webvtt \
    -f tee \
        "[select=\'a:0,v:0,v:1,v:2\': \
          f=dash: \
          seg_duration=2: \
          hls_playlist=true: \
          single_file=true: \
          adaptation_sets=\'id=0,streams=a id=1,streams=v\' : \
          media_seg_name=\'stream_vod_$RepresentationID$-$Number%05d$.$ext$\': \
          hls_master_name=master_vod.m3u8]../vod/10100_20231101212100/manifest_vod.mpd| \
         [select=\'v:0,s:0\': \
          strftime=1: \
          hls_flags=+independent_segments+iframes_only: \
          hls_time=2: \
          hls_playlist_type=event: \
          hls_segment_type=fmp4: \
          var_stream_map=\'v:0,s:0,sgroup:subtitle\': \
          hls_segment_filename=\'/dev/null\']../vod/10100_20231101212100/sub_%v.m3u8| \
          [select=\'v:0,a:0\': \
          f=mp4: \
          movflags=+faststart]10100_20231101212100/10100_20231101212100 - De Avondshow met Arjen Lubach.mp4| \
          [select=\'s:0\']10100_20231101212100/subtitles.vtt| \
          /dev/null| \
          [select=\'a:0,v:0,v:1,v:2\': \
          f=hls: \
          hls_time=2: \
          hls_playlist_type=event: \
          hls_flags=+independent_segments+iframes_only: \
          hls_segment_type=fmp4: \
          var_stream_map=\'a:0,agroup:aac,language:dut,name:aac_0_128k v:0,agroup:aac,name:720p_3200 v:1,agroup:aac,name:480p_1600 v:2,agroup:aac,name:360p_900\': \
          master_pl_name=master_event.m3u8: \
          hls_segment_filename=10100_20231101212100/stream_event_%v_data%02d.m4s]10100_20231101212100/stream_event_%v.m3u8| \
         [select=\'v:0,s:0\': \
          strftime=1: \
          f=hls: \
          hls_flags=+independent_segments+program_date_time: \
          hls_time=2: \
          hls_playlist_type=event: \
          hls_segment_type=fmp4: \
          var_stream_map=\'v:0,s:0,sgroup:subtitle\': \
          hls_segment_filename=\'/dev/null\']10100_20231101212100/sub_%v.m3u8" \
2>>/tmp/ffmpeg-hls-10100_20231101212100.log && \
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode finish success >> /var/www/html/hls/10100_20231101212100/status.txt' || \
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode finish failed >> /var/www/html/hls/10100_20231101212100/status.txt'
while [ ! "`/usr/bin/cat /var/www/html/hls/10100_20231101212100/status.txt | /usr/bin/grep 'encode finish success'`" ] ;
do
    sleep 1;
done
```

### Add subtitles to MP4

In a post-processing step subtitles are added to the `MP4`:

``` shell
cd /var/www/html/hls/10100_20231101212100;
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_merge start >> /var/www/html/hls/10100_20231101212100/status.txt';
cd /var/www/html/hls/10100_20231101212100;
/usr/bin/sudo -uapache /usr/bin/ffmpeg \
    -i "10100_20231101212100 - De Avondshow met Arjen Lubach.mp4" \
    -i subtitles.vtt \
    -c:s mov_text -metadata:s:s:0 language=dut -disposition:s:0 default \
    -c:v copy \
    -c:a copy \
    "10100_20231101212100 - De Avondshow met Arjen Lubach.tmp.mp4" \
2>>/tmp/ffmpeg-subtitle-merge-hls-10100_20231101212100.log && \
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_merge success >> /var/www/html/hls/10100_20231101212100/status.txt' || \
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_merge failed >> /var/www/html/hls/10100_20231101212100/status.txt';
/usr/bin/sudo /usr/bin/mv -f "10100_20231101212100 - De Avondshow met Arjen Lubach.tmp.mp4" "10100_20231101212100 - De Avondshow met Arjen Lubach.mp4"
while [ ! "`/usr/bin/cat /var/www/html/hls/10100_20231101212100/status.txt | /usr/bin/grep 'encode finish success'`" ] ;
do
    sleep 1;
done
/usr/bin/sudo /usr/bin/rm /var/www/html/hls/10100_20231101212100/video.mp4
sleep 3 && /usr/bin/sudo /usr/bin/screen -ls 10100_20231101212100_encode  | /usr/bin/grep -E '\s+[0-9]+.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS $s quit; done
```

### Complete script

<details>

<summary> Click me </summary>

For completeness the whole script is:

``` shell
cd /var/www/html/hls/10100_20231101212100
/usr/bin/sudo /usr/bin/screen -S 10100_20231101212100_remux -dm /usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: remux start > /var/www/html/hls/10100_20231101212100/status.txt;
/usr/bin/sudo -uapache /usr/bin/ffmpeg \
          -y \
          -hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi \
          -txt_format text -txt_page 888 \
          -fix_sub_duration \
          -i /mnt/mythtv2/store//10100_20231101212100.ts \
          -c copy \
          -c:s mov_text \
          /var/www/html/hls/10100_20231101212100/video.mp4 && \
/usr/bin/echo `date`: remux finish success >> /var/www/html/hls/10100_20231101212100/status.txt || \
/usr/bin/echo `date`: remux finish failed >> /var/www/html/hls/10100_20231101212100/status.txt'
while [ ! "`/usr/bin/cat /var/www/html/hls/10100_20231101212100/status.txt | /usr/bin/grep 'remux finish success'`" ] ; \
do \
    sleep 1; \
done
(while [ ! -f "/var/www/html/hls/10100_20231101212100/master_event.m3u8" ] ;
 do
        /usr/bin/inotifywait -e close_write --include "master_event.m3u8"  /var/www/html/hls/10100_20231101212100;
 done;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\1\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="Dutch",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI="sub_0_vtt.m3u8",LANGUAGE="dut"/' /var/www/html/hls/10100_20231101212100/master_event.m3u8;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\1\n#EXT-X-START:TIME-OFFSET=0/' /var/www/html/hls/10100_20231101212100/master_event.m3u8;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\1,SUBTITLES="subtitles"/'  /var/www/html/hls/10100_20231101212100/master_event.m3u8; /usr/bin/sudo -uapache /usr/bin/sudo sed -r '/(#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+\,CODECS)/{N;d;}' -i /var/www/html/hls/10100_20231101212100/master_event.m3u8;) &
(while [ ! -f "/var/www/html/vod/10100_20231101212100/master_vod.m3u8" ] ;
 do
        /usr/bin/inotifywait -e close_write --include "master_vod.m3u8" /var/www/html/vod/10100_20231101212100;
 done;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\1\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="Dutch",DEFAULT=YES,FORCED=NO,AUTOSELECT=YES,URI="sub_0_vtt.m3u8",LANGUAGE="dut"/' /var/www/html/vod/10100_20231101212100/master_vod.m3u8;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-VERSION:7)/\1\n#EXT-X-START:TIME-OFFSET=0/' /var/www/html/vod/10100_20231101212100/master_vod.m3u8;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-STREAM.*)/\1,SUBTITLES="subtitles"/' /var/www/html/vod/10100_20231101212100/master_vod.m3u8;
    /usr/bin/sudo -uapache /usr/bin/sed -i -E 's/(#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="group_A1")/\1,LANGUAGE="dut"/' /var/www/html/vod/10100_20231101212100/master_vod.m3u8;) &
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode start >> /var/www/html/hls/10100_20231101212100/status.txt';
/usr/bin/sudo -uapache /usr/bin/mkdir -p /var/www/html/vod/10100_20231101212100;

/usr/bin/sudo -uapache /usr/bin/mkdir -p /var/www/html/hls/10100_20231101212100;
cd /var/www/html/hls/;
/usr/bin/sudo -uapache /usr/bin/ffmpeg \
    -fix_sub_duration \
    -txt_format text -txt_page 888 \
    -hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi \
     \
     \
    -f concat -async 1 -safe 0 -i /var/www/html/hls/10100_20231101212100/cutlist.txt \
    -progress 10100_20231101212100/progress-log.txt \
    -live_start_index 0 \
    -tune movie \
    -metadata title="De Avondshow met Arjen Lubach" \
    -force_key_frames "expr:gte(t,n_forced*2)" \
    -filter_complex "[0:v]split=3[v1][v2][v3];[v1]scale_vaapi=w=1280:h=720[v1out];[v2]scale_vaapi=w=854:h=480[v2out];[v3]scale_vaapi=w=640:h=360[v3out]" \
    -map [v1out] -c:v:0 \
        h264_vaapi \
        -b:v:0 3200k \
        -maxrate:v:0 3200k \
        -bufsize:v:0 1.5*3200k \
        -crf 23 \
        -preset veryfast \
        -g 48 \
        -keyint_min 48 \
        -sc_threshold 0 \
        -flags +global_header \
    -map [v2out] -c:v:1 \
        h264_vaapi \
        -b:v:1 1600k \
        -maxrate:v:1 1600k \
        -bufsize:v:1 1.5*1600k \
        -crf 23 \
        -preset veryfast \
        -g 48 \
        -keyint_min 48 \
        -sc_threshold 0 \
        -flags +global_header \
    -map [v3out] -c:v:2 \
        h264_vaapi \
        -b:v:2 900k \
        -maxrate:v:2 900k \
        -bufsize:v:2 1.5*900k \
        -crf 23 \
        -preset veryfast \
        -g 48 \
        -keyint_min 48 \
        -sc_threshold 0 \
        -flags +global_header \
 \
    -map a:0 -c:a:0 aac -b:a:0 128k -ac 2 \
        -metadata:s:a:0 language=dut \
 \
    -map 0:s:0? -c:s webvtt \
    -f tee \
        "[select=\'a:0,v:0,v:1,v:2\': \
          f=dash: \
          seg_duration=2: \
          hls_playlist=true: \
          single_file=true: \
          adaptation_sets=\'id=0,streams=a id=1,streams=v\' : \
          media_seg_name=\'stream_vod_$RepresentationID$-$Number%05d$.$ext$\': \
          hls_master_name=master_vod.m3u8]../vod/10100_20231101212100/manifest_vod.mpd| \
         [select=\'v:0,s:0\': \
          strftime=1: \
          hls_flags=+independent_segments+iframes_only: \
          hls_time=2: \
          hls_playlist_type=event: \
          hls_segment_type=fmp4: \
          var_stream_map=\'v:0,s:0,sgroup:subtitle\': \
          hls_segment_filename=\'/dev/null\']../vod/10100_20231101212100/sub_%v.m3u8| \
          [select=\'v:0,a:0\': \
          f=mp4: \
          movflags=+faststart]10100_20231101212100/10100_20231101212100 - De Avondshow met Arjen Lubach.mp4| \
          [select=\'s:0\']10100_20231101212100/subtitles.vtt| \
          /dev/null| \
          [select=\'a:0,v:0,v:1,v:2\': \
          f=hls: \
          hls_time=2: \
          hls_playlist_type=event: \
          hls_flags=+independent_segments+iframes_only: \
          hls_segment_type=fmp4: \
          var_stream_map=\'a:0,agroup:aac,language:dut,name:aac_0_128k v:0,agroup:aac,name:720p_3200 v:1,agroup:aac,name:480p_1600 v:2,agroup:aac,name:360p_900\': \
          master_pl_name=master_event.m3u8: \
          hls_segment_filename=10100_20231101212100/stream_event_%v_data%02d.m4s]10100_20231101212100/stream_event_%v.m3u8| \
         [select=\'v:0,s:0\': \
          strftime=1: \
          f=hls: \
          hls_flags=+independent_segments+program_date_time: \
          hls_time=2: \
          hls_playlist_type=event: \
          hls_segment_type=fmp4: \
          var_stream_map=\'v:0,s:0,sgroup:subtitle\': \
          hls_segment_filename=\'/dev/null\']10100_20231101212100/sub_%v.m3u8" \
2>>/tmp/ffmpeg-hls-10100_20231101212100.log && \
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode finish success >> /var/www/html/hls/10100_20231101212100/status.txt' || \
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: encode finish failed >> /var/www/html/hls/10100_20231101212100/status.txt'
while [ ! "`/usr/bin/cat /var/www/html/hls/10100_20231101212100/status.txt | /usr/bin/grep 'encode finish success'`" ] ;
do
    sleep 1;
done
cd /var/www/html/hls/10100_20231101212100;
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_merge start >> /var/www/html/hls/10100_20231101212100/status.txt';
cd /var/www/html/hls/10100_20231101212100;
/usr/bin/sudo -uapache /usr/bin/ffmpeg \
    -i "10100_20231101212100 - De Avondshow met Arjen Lubach.mp4" \
    -i subtitles.vtt \
    -c:s mov_text -metadata:s:s:0 language=dut -disposition:s:0 default \
    -c:v copy \
    -c:a copy \
    "10100_20231101212100 - De Avondshow met Arjen Lubach.tmp.mp4" \
2>>/tmp/ffmpeg-subtitle-merge-hls-10100_20231101212100.log && \
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_merge success >> /var/www/html/hls/10100_20231101212100/status.txt' || \
/usr/bin/sudo -uapache /usr/bin/bash -c '/usr/bin/echo `date`: subtitle_merge failed >> /var/www/html/hls/10100_20231101212100/status.txt';
/usr/bin/sudo /usr/bin/mv -f "10100_20231101212100 - De Avondshow met Arjen Lubach.tmp.mp4" "10100_20231101212100 - De Avondshow met Arjen Lubach.mp4"
while [ ! "`/usr/bin/cat /var/www/html/hls/10100_20231101212100/status.txt | /usr/bin/grep 'encode finish success'`" ] ;
do
    sleep 1;
done
/usr/bin/sudo /usr/bin/rm /var/www/html/hls/10100_20231101212100/video.mp4
sleep 3 && /usr/bin/sudo /usr/bin/screen -ls 10100_20231101212100_encode  | /usr/bin/grep -E '\s+[0-9]+.' | /usr/bin/awk '{print $1}' - | while read s; do /usr/bin/sudo /usr/bin/screen -XS $s quit; done
```

</details>

# Live TV

## User interface

Figure 7 shows shows the user interface of `hdhomerunstream` while
selecting a TV channel.

**Figure 7:** *Select TV channel.*

<figure id="select-tv-channel" alt="Select TV channel"
title="Select TV channel" data-align="right" width="350px">
<img src="screenshots/select-tv-channel.png" />
<figcaption>Select TV channel.</figcaption>
</figure>

User interface options:

- Select the `ABR` renditions from the select dropdown list box, see
  Figure 2.
- Select the HW acceleration from the list box[^18].
- Select the TV channel from the list box[^19].
- Press **Watch TV** when you are satisfied with your choices to start
  watching.

Figure 8 shows the Live TV user interface.

**Figure 8:** *Live TV user interface.*

<figure id="live-tv-user-interface" alt="Live TV interface"
title="Live TV interface" data-align="right" width="350px">
<img src="screenshots/live-tv.png" />
<figcaption>Live TV user interface.</figcaption>
</figure>

User interface options:

- Select **Stop streaming** when you are done watching[^20]. This cleans
  up the files on disk.
- The status button indicates when the `Live stream is ready`.
- Select `Shutdown Lock` in case one wants to prevent MythTV from
  shutting down.
- The dynamic button at the right hand side indicates the fact that the
  `HLS` manifest file (no DASH support yet) is generated by showing the
  selected channel name.

## Limitations

- The HDHomeRun tuner is hardcoded. The tuner is basically assumed to be
  reserved no checks are implemented.
- Multiple devices can view the same channel. However, no checks are
  implemented when one of them stops the stream.
- Only `HLS` is supported.

# Appendix

## Credits

I would like to thank the [MythTV stream mpeg
DASH](https://github.com/thecount2a/mythtv-stream-mpeg-dash) project for
giving me the inspiration!

## License

MythTV-stream-hls-dash is licensed under the GPLv3, see LICENSE for
details.

## Patches

Feedback, patches, other contributions and ideas are welcome!

# Footnotes

[^1]: `mythffmpeg` can be used instead, but does not support subtitles.

[^2]: May depend on your distribution (e.g. 'data-www' is used for
    Ubuntu). May require one to adapt the `php` scripts.

[^3]: This requires mythtv v34.

[^4]: A subset of the user interface is used after selecting a video in
    [Web Application](https://www.mythtv.org/wiki/Web_Application).

[^5]: The dropdown list shows the recordings available for streaming.
    Leave as is since we are defining the settings for this recording.

[^6]: Only VAAPI and no HW acceleration has been tested. Feedback on
    untested acceleration is appreciated.

[^7]: This option is only visible in the UI when a `Cutlist` is defined
    in MythTV.

[^8]: This option is only visible when subtitles are available in the
    video file. The language depends on the configuration in the php
    files. Adapt to your liking.

[^9]: Either one of the two or none at all.

[^10]: This will not delete any file from MythTV or change the MySQL
    database. All files can be recreated as long as the recording is
    available in MythTV.

[^11]: If no still of the output is shown after 30 seconds, push the
    `HLS event` of `HLS VOD` button. As a last resort try to reload the
    browser page.

[^12]: This button is only shown when both playlist types `event` and
    `VOD` were selected as shown in Figure 1.

[^13]: Realtime.

[^14]: After Post-processing.

[^15]: All can be combined with `ABR`, `Cut commercials` and `subtitles`
    selection.

[^16]: One minute of playback.

[^17]: The `cutlist` itself was defined in MythTV which is translated
    into the inpoint's and outpoint's of the `cutlist` for the video.

[^18]: Only VAAPI and no HW acceleration has been tested. Feedback on
    untested acceleration is appreciated.

[^19]: The channel information is extracted from MythTV automagically.

[^20]: FFmpeg encoding is stopped without checking if other users are
    watching the stream.
