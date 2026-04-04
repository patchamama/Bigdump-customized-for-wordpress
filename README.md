# BigDump

## Staggered MySQL Dump Importer

**Description**: Staggered import of large and very large MySQL Dumps (like phpMyAdmin dumps) even through the web servers with hard runtime limit and those in safe mode. The script imports only a small part of the huge dump and restarts itself. The next session starts where the last was stopped.

## Credits

This code is originally written by [Alexey Ozerov][3].

## Usage

1. Download and unzip [_bigdump.zip_][1] on your PC.
2. Open _bigdump.php_ in a text editor, adjust the database configuration and dump file encoding.
3. Drop the old tables on the target database if your dump doesn't contain "DROP TABLE" (use phpMyAdmin).
4. Create the working directory (e.g. _dump_) on your web server
5. Upload _bigdump.php_ and the dump files (*.sql or *.gz) via FTP to the working directory (take care of **TEXT** mode upload for _bigdump.php_ and _dump.sql_ but **BINARY** mode for _dump.gz_ if uploading from MS Windows).
6. Run the _bigdump.php_ from your web browser via URL like _http://www.yourdomain.com/dump/bigdump.php_.
7. Now you can select the file to be imported from the listing of your working directory. Click "Start import" to start.
8. BigDump will start every next import session automatically if JavaScript is enabled in your browser.
9. Relax and wait for the script to finish. Do NOT close the browser window!
10. **IMPORTANT:** Remove _bigdump.php_ and your dump files from your web server.

## Advanced notes

**Note 1:** BigDump will fail processing large tables containing extended inserts. An extended insert contains all table entries within one SQL query. BigDump isn't able to split such SQL queries. In most cases BigDump will stop if some query includes to many lines. But if PHP complains that _allowed memory size exhausted_ or _MySQL server has gone away_ your dump probably also contains extended inserts. Please turn off extended inserts when exporting database from phpMyAdmin. If you only have a dump file with extended inserts please ask for our [support service][2] in order to convert it into a file usable by BigDump.

**Note 2:** If you want to upload the dump files via web browser give the scripts writing permissions on the working directory (e.g. make chmod 777 on a Linux based system). You can upload the dump files from the browser up to the size limit set by the current PHP configuration of the web server. Alternatively you can upload any files via FTP. Some web servers disallow script execution in the directory with writing permissions for security reasons. If you changed the permissions on the working directory and you are getting a server error when running the script restore the permissions to their normal state (chmod 755) for directories.

**Note 3:** If Timeout errors still occur you may need to adjust the $linespersession setting in _bigdump.php_.

**Note 4:** If mySQL server overrun occurs due to max_requests restriction you can use $delaypersession setting to let the script sleep some milliseconds or more before starting next session. This setting will only work if the JavaScript is activated. Importing dump file on such servers may be very time consuming.

**Note 5:** BigDump is currently not able to restore a single dump file with multiple databases inside (switched by the USE statement). BigDump is also not able to restore a single specific database from the dump file containing multiple databases.

**Note 6:** If you experience problems with non-latin characters while using BigDump you have to adjust the $db_connection_char_set configuration variable in _bigdump.php_ to match the encoding of your dump file.

**Note 7:** GZip support is only available with PHP 4.3.0 and later. Using a huge GZip compressed dump file can cause the script to exceed the PHP memory/runtime limit since the dump file has to be unpacked from the beginning every time the session starts. If this happens use the uncompressed dump. It's your only chance.

**Note 8:** It's not a very good idea, but if you can also import from CSV file into one mySQL table using Bigdump. You have to specify the table name in $csv_insert_table. Please also check other CSV settings in the Bigdump configuration.

War dieser Beitrag hilfreich? Empfehlen Sie ihn weiter!  

[1]: http://www.ozerov.de/bigdump.zip
[2]: http://www.ozerov.de/bigdump/support/ "Support"
[3]: http://www.ozerov.de/bigdump/
