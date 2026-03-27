#! /bin/bash
mkdir -p dist/keycloakoauth-package/packages

rm -f dist/keycloakoauth-package/packages/plg_authentication_keycloakoauth.zip
rm -f dist/keycloakoauth-package/packages/plg_system_keycloakoauth.zip
rm -f dist/*.zip

cd plugins/authentication/keycloakoauth
zip -rq ../../../dist/keycloakoauth-package/packages/plg_authentication_keycloakoauth.zip .

cd ../../system/keycloakoauth
pwd
zip -rq ../../../dist/keycloakoauth-package/packages/plg_system_keycloakoauth.zip .

cd ../../../dist/keycloakoauth-package && \
zip -rq ../pkg_keycloakoauth_1.0.0.zip .
