# mythtv-stream-hls-dash

Will allow you to transcode and stream any mythtv recording to be watched via the browser

Features:
* Transcodes from whatever format your recordings are in, as long as they are recognized by ffmpeg
* Watch recording while transcode is still taking place (just don't seek too far ahead)
* Implements live, hls, dash, mp4, and vod streaming
* Use commercial cut info from mythtv database to cut commercials
* Can transcode videos to configurable multiple bitrates/resolutions for adaptive playback over less reliable networks (e.g. cell phone browser).
* Allows watching recordings that are currently being recorded (live recordings)
* Allows live hdhomerun streams

This depends on:
* mythtv (for commerical cut info and looking up the name of each recording based on filename)
  * I use version v0.33
* ffmpeg (for transcoding)
  * I use ffmpeg version 5.1.3
* GNU screen
  * This is to allow monitoring of transcode and packager and to support background processes launched by the web-facing PHP script
  * apt-get install screen
* Shaka player
  * This is the Javascript-based browser player that plays MPEG DASH content
  * I use version 4.3.6
