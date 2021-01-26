# Bulldozer

A simple PHP script to move images from one directory into /yyyy/mm/dd directories based on date created.

By [Colin Devroe](http://cdevroe.com/projects/bulldozer)

## 1. Setup

- To configure, see `bulldozer.php`
- Change the default location to a folder that exists on your

## 2. Instructions for use

`php move.php Location true/false`

To run, use Terminal on Mac to run PHP using the command above. Replace _Location_ with the location you've set up in the file.

## Other notes

- At least two directories are required; the directory of original files to copy, and the destination.
- You can set up as many locations as you'd like. For example, you can copy the images to a backup drive, a second backup drive, and cloud storage directory.
- The script is intended to be used with images, such as JPG, PNG, or HEIC, however it can be used with any file type.


## Version History

- **2021.01** - January 25, 2021
    - Initial public release