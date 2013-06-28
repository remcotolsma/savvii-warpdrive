# To use you need to be in the nginx group on linode
if [ $# -eq 1 ]
then
	while true
	do
		# rsync - recursive, skip on checksum, human readable, compress, verbose
		rsync -rchzv src/ linode:/var/nginx/wp-dev/web/wp-content/plugins/warpdrive/
		# Sleep 1s
		sleep 2
	done
else
	# rsync - once, skip on checksum, human readable, compress, verbose
	rsync -rchzv src/ linode:/var/nginx/wp-dev/web/wp-content/plugins/warpdrive/
fi
