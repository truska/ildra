# Old site image library

Admin → Manage Old Library is under the existing Admin section. Admin, Manager,
Superadmin and Developer can use it. The existing Document & Image Library is
separate. This temporary library is offered only for saved Events, Rider Notes and Venues.
Save editor changes before opening the chooser.

## Add images whenever needed

Drop files through VS Code or FTP into:

`/var/www/dev-ildra.witecanvas.com/private/old-image-library/incoming/`

Use a flat folder; subfolders are not scanned. JPG, PNG, GIF and WebP are supported,
up to 50 MB and 40 million pixels each. Finish transfers before processing. For FTP,
use temporary extensions during transfer and rename on completion. Files modified
within 30 seconds are skipped until a later run.

In Manage Old Library, press **Process new images**. Each click processes at most
12 valid new images. Repeat if more remain. Add further deliveries to the same
folder and process again. Contents are fingerprinted: identical files are skipped,
even if renamed. The first processed original filename remains searchable. A
changed image with different contents is a new library entry. Invalid files are
reported and can be removed or corrected in incoming.

Processing stores a separate private original under `private/old-image-library/originals/`
and a thumbnail bounded by 240 pixels under `filestore/images/old-library/thumb/`.
Incoming files are retained. Browsing searches original filenames and displays
24 thumbnails per page with lazy loading; originals are not downloaded for selection.
Animated GIF thumbnails/renditions are static.

## Use an image

In Events, Rider Notes or Venue Images, choose **Choose from Old Library**, search for a ride
name or another filename word, then tap **Use this image**. The server copies the
original byte for byte into the destination's normal image folder, creates the
normal destination sizes and adds a gallery record. Adjust alternative text,
caption and gallery order in that editor. The first active image remains the
main image according to the existing gallery rules.

## Switch off and clean up

Uncheck **Offer Old Library in Events, Rider Notes and Venues** and save availability.
All three chooser links disappear and direct selection requests are blocked.
Management and cleanup remain available. Attached images continue working.

Select library images individually or select the current page, type `DELETE`,
and remove them. This deletes only library originals and thumbnails. It retains
incoming files and a fingerprint record so processing cannot reintroduce a
removed image. Use subsequent pages for further cleanup. Usage counts record
historical selections, rather than a live count of current attachments.

After retirement, the incoming source files can be backed up and removed separately.
Removing all library files does not remove independently attached gallery copies.
Physical removal of the feature should remove the three editor links, the management
menu entry/page and helper, then its library table/storage after backup. Normal
Events, Rider Notes and Venues have no runtime dependency on library storage for display.

## Setup and verification

The table is created additively when management is opened. The feature setting
`old_image_library_enabled` defaults to 1. Source files are not automatically
processed; the Process action is an explicit import of the images in incoming.
No old site images were supplied or processed during implementation.

Pre-create incoming, originals and thumbnail directories on each environment.
The PHP worker must traverse private storage, read incoming files and write to
originals, the processing lock, thumbnails and existing destination rendition
folders. Preserve shared-write/default ACL access for files added over FTP.
Development uses a named `web7` ACL on the new directories; other environments
must use their own PHP worker. Never expose private originals via a public alias.

Run `php scripts/tests/old_image_library.php` for isolated temporary database-table
checks with synthetic images. It checks repeated and later batches, invalid
images, portrait thumbnail dimensions, original preservation, independent copies,
deleted fingerprints and the disable guard. PHP worker writes to all library and
Events/Rider Notes destination folders were verified on development.
