# Subtitle Derivative

This module provides a Drupal action for Islandora that transforms the contents of media and saves it as a new media on the same node. An example use case is generating plaintext from WEBVTT, or converting Subrip Text to WEBVTT.

This module uses [mantas-done/subtitles](https://github.com/mantas-done/subtitles) for the transforms.

## Configuration

When you create a Derivative action, you must specify a file system and path to save it in. You must specify a term that indicates the source media, and for the destination media you must specify a term, format, media type, mime type, file system and path. For the destination path, token replacement from the parent `node`, source `media`, and destination `term` is available.

## Copyright

This software is Copyright (C) 2026 University of Pittsburgh and is available under the MIT license.
