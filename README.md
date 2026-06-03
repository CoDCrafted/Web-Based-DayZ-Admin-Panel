# Web-Based-DayZ-Admin-Panel
Originally made for console server owners who lack the ability for custom in-game UIs, this tool allows you to remotely run commands on your DayZ server without being logged in and without requiring any additional mods, it is all init.c based. Seeing as Nitrado no longer allows users to modify the init.c file, I deciced there was no point gatekeeping this so I will just release it for use on steam servers instead. This was never really intended to be released to the end-user so it is presented "as-is" and some tweaking may be required to get it fully working; some dependency files (namely the entirety of "essential/internal.php") have been omitted as it contains far too much sensitive code relating to my own internal systems.

If you would like to make a fork please do, the intention behind releasing this was to give others the opportunity to contribute to it.

# How To Use

DayZ Server Install:

To install the panel, simply replace the existing init.c file in your missions folder with the one provided, and place adminCommands.c in the "custom" folder in your missions folder. Using any text editor or IDE, open adminCommands.c and change the target domain from cyenox.com to your own domain. Also change the panel code to anything you like.

Web GUI Setup:

1. Purchase a web server & domain. I suggest using namecheap for this as you can get a relatively cheap shared server there.
2. In the root of your html documnents directory, place the "dayz" folder.
3. Use the SQL build script to create a database table and name it whatever you like.
4. In "essential/config.php" configure your database name and password.

How to use the panel:

On the panel enter the panel code that you set in adminCommands.c in the "code" box. To verify it is working as intended, click "get player list" and if all has been set up correctly, it should display the entire player list of that server.
