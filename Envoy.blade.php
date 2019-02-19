
@setup
	require __DIR__.'/vendor/autoload.php';
	$dotenv = new Dotenv\Dotenv(__DIR__);
	try {
		$dotenv->load();
	} catch ( Exception $e )  {
		echo $e->getMessage();
	}
	$apps = [
		'api',
		'shop',
		'admin',
		'cereda_shop',
		'cereda_admin',
		'debtor_api'
	];

	$envs = [
		'dev',
		'prod',
		'stag',
		'qa'
	];

	$branches = [
		'develop',
		'master'
	];

	if(!isset($app)) {
		throw new \Exception("Undefined application");
	}
	if(!in_array($app, $apps)) {
		throw new \Exception("Undefined application");
	}
	if(!isset($env)) {
		throw new \Exception("Undefined environment");
	}
	if(!in_array($env, $envs)) {
		throw new \Exception("Undefined deploy type");
	}

	echo "test".strtoupper($app);
	$appPrefix = getenv(strtoupper($app).'_PREFIX');
	$server = getenv(strtoupper($app).'_DEPLOY_SERVER_'.strtoupper($env));
	$repo = getenv(strtoupper($app).'_DEPLOY_REPOSITORY');
	$path = getenv(strtoupper($app).'_DEPLOY_PATH');
	$slack = getenv(strtoupper($app).'_DEPLOY_SLACK_WEBHOOK');
	$healthUrl = getenv(strtoupper($app).'_DEPLOY_HEALTH_CHECK');

	$branch = isset($branch) ? $branch : "develop";
	if(!isset($branch)) {
		throw new \Exception("Unsupported branch");
	}

	if(!in_array($branch, $branches)) {
		throw new \Exception("Unsupported branch");
	}
	$path = rtrim($path, '/');

@endsetup

@servers(['web' => $server, 'localhost' => '127.0.0.1'])

@task('lara_init_env_file', ['on' => 'localhost'])
	echo "Environment file set up $env $appPrefix";
	#echo "/home/antonio88/Desktop/env_files/{{ $env }}/{{ $appPrefix }}/.env  {{ $server }}:{{ $path }}";
	#aws s3 cp s3://wm-cf-templates/env_files/{{ $env }}/{{ $appPrefix }}/.env ./.env

	echo "scp -r -i /home/antonio88/.ssh/Weerman_{{ $env }}_eu-west-1.pem /home/antonio88/Desktop/env_files/{{ $env }}/{{ $appPrefix }}/.env {{ $server }}:{{ $path }}/";
	scp -r -i /home/antonio88/.ssh/Weerman_{{ $env }}_eu-west-1.pem /home/antonio88/Desktop/env_files/{{ $env }}/{{ $appPrefix }}/.env {{ $server }}:{{ $path }}/
@endtask

@task('lara_permission_setup', ['on' => 'web'])
	echo "Permissions  setup";
cd {{ $path }}
    sudo chown -R www-data:www-data .
	sudo find . -type d -exec chmod 755 {} \;
	sudo find . -type f -exec chmod 644 {} \;
	sudo chgrp -R www-data storage bootstrap/cache
	sudo chmod -R ug+rwx storage bootstrap/cache
	sudo chmod 777 -R public
	#sudo chown -R ubuntu:www-data public/build
@endtask

@task('lara_install_dependencies', ['on' => 'web'])
	echo "Install composer";
	export COMPOSER_ALLOW_SUPERUSER=1
	composer install --prefer-dist --no-dev --optimize-autoloader
@endtask

@task('lara_database_migrate', ['on' => 'web'])
	# Migrate DB
	#php artisan migrate --step
	if [ $app = "api" ]; then
		mkdir {{ $path }}/database/migrations/test
		cp {{ $path }}/database/migrations/2018_03_09_140652_multi-server-event.php {{ $path }}/database/migrations/test
		php artisan migrate --migrate=database/migrations/test
	fi
@endtask

@task('lara_yarn_dependencies', ['on' => 'web'])
	#NPM dependencies
	yarn install
	#Gulp
	#sudo npm rebuild node-sass
	sudo gulp --production
	#only shop , nad admin?
	php artisan template:css
@endtask

@task('lara_after_install', ['on' => 'web'])

	# Clear any previous cached views and optimize the application
	php artisan cache:clear
	php artisan view:clear
	php artisan route:clear
	php artisan config:clear
	php artisan clear-compiled
	php artisan optimize
	php artisan config:cache
	php artisan route:cache
@endtask

@task('lara_restart_services', ['on' => 'web'])
	echo "Reload PHP-FPM and Nginx";
	sudo service php7.1-fpm reload
	sudo service nginx restart
	sudo service nginx reload

	echo "Supervisor restart processes";
	sudo supervisorctl restart all
	php artisan queue:restart

	echo "Bring application up";
	php artisan up
@endtask

@story('lara')
	start_deployment
	lara_init_env_file
	lara_permission_setup
	lara_install_dependencies
	lara_database_migrate
	lara_yarn_dependencies
	lara_after_install
	lara_restart_services
	health_check
	end_deployment
@endstory

@story('mage')
	start_deployment
	mage_init_env_file
	mage_permission_setup
	mage_install_dependencies
	mage_yarn_dependencies
	mage_after_install
	mage_restart_services
	health_check
	end_deployment
@endstory

@task('start_deployment', ['on' => 'web'])
	echo "Starting deployment on s <$env> environment branh <$branch> app <$appPrefix>";
	rm -rf {{ $path }}
	mkdir {{ $path }}
	cd /var/www/html
	#git clone https://uueqast:tpezzqast1zzzzz790@github.com/Typeqast/WeermanCentralApi.git
	git clone {{ $repo }}

	#cd {{ $path }}
	#git checkout develop
    #git pull origin develop
@endtask

@task('health_check')
	@if ( ! empty($healthUrl) )
		if [ "$(curl --write-out "%{http_code}\n" --silent --output /dev/null {{ $healthUrl }})" == "200" ]; then
		printf "\033[0;32mHealth check to {{ $healthUrl }} OK\033[0m\n"
		else
		printf "\033[1;31mHealth check to {{ $healthUrl }} FAILED\033[0m\n"
		fi
	@else
		echo "No health check set"
	@endif
@endtask

@task('end_deployment', ['on' => 'web'])
	echo "Optimize things";
	composer dump-autoload -o
	echo "Deployment done successfully";
@endtask

@task('ssh_setup', ['on' => 'web'])
	# Start SSH agent & add identity to the agent
	#killall ssh-agent; eval `ssh-agent`
	#ssh-add ~/.ssh/Weerman_dev_eu-west-1.pem
	#ssh-add ~/.ssh/Weerman_prod_eu-west-1.pem
	#ssh-add ~/.ssh/Weerman_qa_eu-west-1.pem
@endtask

@task('mage_init_env_file', ['on' => 'localhost'])
	echo "Environment file set up $env $appPrefix";
	#echo "/home/antonio88/Desktop/env_files/{{ $env }}/{{ $appPrefix }}/.env  {{ $server }}:{{ $path }}";
	#aws s3 cp s3://wm-cf-templates/env_files/{{ $env }}/{{ $appPrefix }}/.env ./.env

	echo "scp -r -i /home/antonio88/.ssh/Weerman_{{ $env }}_eu-west-1.pem /home/antonio88/Desktop/env_files/{{ $env }}/{{ $appPrefix }}/.env {{ $server }}:{{ $path }}/app/etc";
	scp -r -i /home/antonio88/.ssh/Weerman_{{ $env }}_eu-west-1.pem /home/antonio88/Desktop/env_files/{{ $env }}/{{ $appPrefix }}/.env {{ $server }}:{{ $path }}/app/etc
@endtask

@task('mage_permission_setup', ['on' => 'web'])
    sudo chown -R www-data:www-data .

	# Install user
	#sudo adduser magento_user
	#sudo passwd magento_user_password
	#sudo usermod -a -G www-data magento_user

    echo "Permissions  setup";
	cd {{ $path }} && find var generated vendor pub/static pub/media app/etc -type f -exec chmod u+w {} + && find var generated vendor pub/static pub/media app/etc -type d -exec chmod u+w {} + && chmod u+x bin/magento

@endtask

@task('mage_install_dependencies', ['on' => 'web'])
	echo "Install composer";
	export COMPOSER_ALLOW_SUPERUSER=1
	composer install --prefer-dist --no-dev --optimize-autoloader
@endtask

@task('mage_yarn_dependencies', ['on' => 'web'])
	#NPM dependencies
	yarn install
	# Grunt
	grunt:clean
    grunt:exec
    grunt:less
@endtask

@task('mage_after_install', ['on' => 'web'])
	echo "Set Deploy mode";
	rm -rf var/cache/* generated/metadata/* generated/code/* var/view_preproccessed/* var/page_cache/* pub/static/*
	php bin/magento deploy:mode:set production

	echo "Clear any previous cached views and optimize the application";
   # php bin/magento dev:source-theme:deploy en_US nl_NL
    php bin/magento setup:static-content:deploy en_US nl_NL
    php bin/magento setup:di:compile
    php bin/magento maintenance:enable
    php bin/magento setup:upgrade --keep-generated
	php bin/magento setup:store-config:set --base-url="http://localhost:8080/"
#php bin/magento setup:store-config:set --base-url="http://localhost:8080/"
#php bin/magento setup:store-config:set --base-url-secure="https://localhost:8080/"
    php bin/magento cache:flush
    php bin/magento cache:clean
	php bin/magento indexer:reindex
	php bin/magento maintenance:disable
@endtask

@task('mage_restart_services', ['on' => 'web'])
	echo "Reload PHP-FPM and Nginx";
	sudo service php7.2-fpm reload
	sudo service nginx restart
	sudo service nginx reload

	echo "Supervisor restart processes";
	sudo supervisorctl restart all

@endtask

{{--
@finished
	@slack($slack, '#deployments', "Deployment on {$server} completed")
@endfinished
--}}


