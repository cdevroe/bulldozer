<?php
/*
    Bulldozer
    Created by Colin Devroe
    http://cdevroe.com/projects/bulldozer

    For help, see readme.md
*/


/* Configuration */
$directory_of_files                 = '/Users/cdevroe/Desktop/bulldozer_images_to_copy';        // Where the original files reside

$locations                          = [];                                                       // Do not change
$locations['default']               = '/Users/cdevroe/Desktop/bulldozer_image_library';         // Default location to copy to, keep name as default
$locations['backup']                = '/Volumes/Name_Of_Volume/';                               // Secondary location (make as many as you'd like)

$filename_prefix_for_duplicates     = 'duplicate_';                                             // E.g. result duplicate_IMG_0001.jpg

/* End Configuration */


// Arguments for PHP
$confirmed                          = (isset($argv[2]) && $argv[2] == 'true') ? $argv[2] : false; // true = actually do work, false is a test
$location_to_copy_to                = (isset($argv[1])) ? $argv[1] : 'default';

// Some setup variables
$directory_of_copied_files          = $locations[$location_to_copy_to];
$number_of_files_copied             = 0;
$number_of_directories_created      = 0;
$number_of_possible_duplicates      = 0;
$files_to_copy                      = getAllFilesIn( $directory_of_files );

// Simple check to see if destination exists
if ( !file_exists($directory_of_copied_files) ) :
    print 'Destination directory does not exist or is not mounted.';
    exit;
endif;

/*
    Loop through all files
    If not a directory or weird file:
     - Determine what the Created Date is
     - Create directories based on the Created Date YYYY/MM/DD
     - Copy the file into the appropriate directory
     - Iterate count
*/
foreach ( $files_to_copy as $file ):

        $file_date_created              = filemtime( $file );
        $directory_to_create            = $directory_of_copied_files . DIRECTORY_SEPARATOR . date( "Y/m/d", $file_date_created );

        if ( !file_exists( $directory_to_create ) ) : // Directory does not exist
            if ( $confirmed ) : // Not a test, create directory and increment count
                mkdir( $directory_to_create, 0777, true );
            endif;
            $number_of_directories_created++;
        endif;

        if ( !file_exists( $directory_to_create . DIRECTORY_SEPARATOR . basename($file) ) ) :
            if ( $confirmed ) :
                copy( $file, $directory_to_create . DIRECTORY_SEPARATOR . basename($file));
            endif;
            $number_of_files_copied++;
        else :
            if ( $confirmed ) :
                copy( $file, $directory_to_create . DIRECTORY_SEPARATOR . $filename_prefix_for_duplicates . basename($file));
            endif;
            $number_of_possible_duplicates++;
        endif;

endforeach;

if ( $confirmed ) :
    print "$number_of_directories_created directories created and $number_of_files_copied files copied. $number_of_possible_duplicates were marked as possible duplicates.";
else : // Tests do not show number of directories created
    print "$number_of_files_copied files would have been copied. $number_of_possible_duplicates would be marked as possible duplicates.";
endif;

// Function to get all files within a directory and its subdirectories.
function getAllFilesIn( $path = '', &$name = array() ) {
    global $directory_of_copied_files;

    $path               = $path == '' ? dirname(__FILE__) : $path;
    $lists              = @scandir($path);
 
    if ( !empty($lists) ) :
        
        foreach ( $lists as $f ) :
            if ( is_dir( $path.DIRECTORY_SEPARATOR.$f ) && $f == $directory_of_copied_files ) { continue; }
            
            if ( is_dir( $path.DIRECTORY_SEPARATOR.$f ) && $f != ".." && $f != "." && $f != ".DS_Store" ) :

                getAllFilesIn( $path.DIRECTORY_SEPARATOR.$f, $name );

            else :

                if( $f != ".." && $f != "." && $f != ".DS_Store" ) :
                    $name[] = $path.DIRECTORY_SEPARATOR.$f;
                endif;

            endif;

        endforeach;

    endif;
  return $name;
}
?>