
## MP3 Quick Search

Copyright 2005, David De Grave <david@ledav.net>

This easy-to-use tool will let you manage your mp3 files directly from your
directory structure.  No database or complicated stuff...  Few tools to
install and setup, few changes in search.php header, a webserver and you are
ready to go.

Main features:
- User / Group access
- Advanced / Normal modes
- View / Modify mp3 v1 header tag (and strip the v2 tags)
- Rename / Trash / Play / Search the mp3(s)
- "Mark" the corrupted ones (move them in a separate directory)
- Upload new mp3 and manage them
- Generate a winamp playlist (.m3u)
- Manage multiple playlists that you can use in a shoutcast webradio
- ...

I use this for myself so, it's not very well documented but should not be
too complex to follow and adapt to your needs...

The following is a working example using an apache's 2.4+ virtual host
config and this repo as the basic "htdocs" structure:

```text
/mp3-quick-search
├── auth
│   ├── group
│   └── passwd
├── includes
│   ├── auth.class.php
│   ├── logmgr.class.php
│   ├── playlist.class.php
│   └── resultsetbrowser.class.php
├── LICENSE
├── logs
│   ├── access.log
│   ├── errors.log
│   └── search.log
├── playlists
│   └── playlist.lst
├── README.md
└── site
    ├── cd01
    │   ├── Example mp3 song 1.mp3
    │   ├── Example mp3 song 2.mp3
    │   └── Example mp3 song 3.mp3
    ├── cd02
    │   └── Example mp3 song 1 from CD02.mp3
    ├── corrupt
    │   └── Corrupted mp3 at position 23sec.mp3
    ├── new
    │   └── New example mp3 not yet classified.mp3
    ├── new.ok
    │   └── Example mp3 marked as good. To be moved manually later in cd02 for example.mp3
    ├── search.css
    ├── search-genres.txt
    └── search.php
```

Virtual host config:

```xml
<VirtualHost *:443>
	ServerName	mp3.example.com

	DocumentRoot    /mp3-quick-search/site
	ServerAdmin     mp3master@example.com

	DirectoryIndex  search.php

	SSLEngine  on
	SetEnv     HTTPS 1

	CustomLog /mp3-quick-search/logs/access.log common
	ErrorLog  /mp3-quick-search/logs/errors.log

	<Directory "/mp3-quick-search">
		Options None
		AllowOverride None
		Require all denied
	</Directory>

	# If using search.php's authentication form:
	<Location "/">
		SSLRequireSSL
		Require ip 192.168.1.0/24
	</Location>

	# If using apache authentication, comment the previous location bloc
	# and uncomment the following one.  You will then need to use
	# `htpasswd /mp3-quick-search/auth/passwd foo` to change/create a
	# hashed password for the user 'foo' ... And you will also need to
	# change the line 51 in search.php about `$useApacheAuth=true` ...

	#<Location "/">
	#	SSLRequireSSL
	#	AuthType          Basic
	#	AuthName          "mp3 Access"
	#	AuthBasicProvider file
	#	AuthUserFile      /mp3-quick-search/auth/passwd
	#	AuthGroupFile     /mp3-quick-search/auth/group
	#	Require valid-user
	#	Require ip 192.168.1.0/24
	#</Location>
</VirtualHost>
```

Change the domain name `mp3.example.com` and the ip range as appropriate to
suit your needs...  Or change your `/etc/hosts` to redirect
`mp3.example.com` to the ip of your web server.  This is just an example...

Then you should be able to browse `https://mp3.example.com/` from your
workstation in the `192.168.1.x` ip range as a regular user `foo` with the
password `bar`.  And as an administrator under `admin` and password `admin`.

Feel free to submit your fixes, questions, open issues, pull requests, ...
You are welcome ;-)

David.
