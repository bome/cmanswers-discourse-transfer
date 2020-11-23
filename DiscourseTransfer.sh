#!/bin/bash
SCRIPTDIR="$(cd "$(dirname "$0")" ; pwd)/discourse-transfer"
CP="$SCRIPTDIR/target/discourse-transfer-1.0.jar"
LIBDIR="$SCRIPTDIR/target/dependency"
for i in "$LIBDIR"/*.jar ; do
	CP="$CP:$i"
done
PACKAGE=net.jthink.discoursetransfer
CLASS=DiscourseTransfer

#echo "exec java -cp "$CP" $PACKAGE.$CLASS $@"
#echo "exec java $PACKAGE.$CLASS $@"
exec java -cp $CP $PACKAGE.$CLASS $@
