warpdrive
=========

Savvii hosting plugin

Branches:
master - main deployment branch
develop - testing branch

## How to update
* Download zip of master branch
* Scp folder to nginx.savviihq.com (own user, folder /tmp)
* Ssh to nginx.savviihq.com
* Move warpdrive folder from /tmp to /opt/savvii
* Check if the user and group of warpdrive folder is root:root
* Replace old mu-plugins with new: rm -rf mu-plugins;mv warpdrive-master mu-plugins
