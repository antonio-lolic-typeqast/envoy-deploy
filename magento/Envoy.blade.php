
@setup
	require __DIR__.'/vendor/autoload.php';
	$dotenv = new Dotenv\Dotenv(../__DIR__);
	try {
		$dotenv->load();
	} catch ( Exception $e )  {
		echo $e->getMessage();
	}

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


	if(!isset($env)) {
		throw new \Exception("Undefined environment");
	}
	if(!in_array($env, $envs)) {
		throw new \Exception("Undefined deploy type");
	}

	echo "test".strtoupper($app);
	$appPrefix = getenv('MAGENTO_PREFIX');
	$server = getenv('MAGENTO_DEPLOY_SERVER_'.strtoupper($env));
	$repo = getenv('MAGENTO_DEPLOY_REPOSITORY');
	$path = getenv('MAGENTO_DEPLOY_PATH');
	$slack = getenv('MAGENTO_DEPLOY_SLACK_WEBHOOK');
	$healthUrl = getenv('MAGENTO_DEPLOY_HEALTH_CHECK');

	$branch = isset($branch) ? $branch : "develop";
	$install = isset($install) ? $install : "no";
	if(!isset($branch)) {
		throw new \Exception("Unsupported branch");
	}

	if(!in_array($branch, $branches)) {
		throw new \Exception("Unsupported branch");
	}
	$path = rtrim($path, '/');

@endsetup

@servers(['web' => $server, 'localhost' => '127.0.0.1'])


@story('deploy')
	start_deployment
	init_env_file
	permission_setup
	install_dependencies
	yarn_dependencies
	after_install
	restart_services
	end_deployment
@endstory

@task('start_deployment', ['on' => 'web'])
	echo "Starting deployment on s <$env> environment branh <$branch> app <$appPrefix>";

	cd /var/www/html/WeermanMagento2
	git checkout develop
	git pull origin develop

	#rm -rf {{ $path }}
	#mkdir {{ $path }}
	#echo "Cloning repository..";
	#git clone {{ $repo }}
@endtask

@task('end_deployment', ['on' => 'web'])
	echo "Optimize things";
	composer dump-autoload -o
	echo "Deployment done successfully";
@endtask

@task('init_env_file', ['on' => 'localhost'])
	echo "Environment file set up $env $appPrefix";
	#aws s3 cp s3://wm-cf-templates/env_files/{{ $env }}/{{ $appPrefix }}/.env ./.env

	scp -r -i /home/antonio88/.ssh/Weerman_{{ $env }}_eu-west-1.pem /home/antonio88/Desktop/env_files/{{ $env }}/{{ $appPrefix }}/env.php {{ $server }}:{{ $path }}/app/etc
@endtask

@task('permission_setup', ['on' => 'web'])

	# Install user
	#sudo adduser magento_user
	#sudo passwd magento_user_password
	#sudo usermod -a -G www-data magento_user
    echo "Permissions  setup";
	cd {{ $path }}
	sudo usermod -a -G www-data ubuntu
	#sudo find var generated vendor pub/static pub/media app/etc -type f -exec chmod u+w {} \;
    #sudo find var generated vendor pub/static pub/media app/etc -type d -exec chmod u+w {} \;

	#sudo find . type f -exec chmod 644 {} \;
	#sudo find . -type d -exec chmod 755 {} \;
	sudo find ./var -type d -exec chmod 777 {} \;
	sudo find ./pub/media -type d -exec chmod 777 {} \;
	sudo find ./pub/static -type d -exec chmod 777 {} \;
	sudo chmod 777 ./app/etc

	#sudo chmod 644 ./app/etc/*.xml
    sudo chmod u+x bin/magento
    sudo chown -R ubuntu:www-data var/log/*

	#important
	sudo chmod -R 777 var/ pub/ generated/
	sudo chown -R ubuntu:www-data generated
	sudo chmod -R u+w generated

	#fix for generation is read yonly bla bla
	sudo find generated -type d -exec chmod 777 {} \;
	sudo find generated -type f -exec chmod 777 {} \;
	sudo find pub -type d -exec chmod 777 {} \;
	sudo find pub -type f -exec chmod 777 {} \;

    sudo find generated -type d -exec chmod 777 {} \; && sudo find generated -type f -exec chmod 777 {} \;
@endtask

@task('install_dependencies', ['on' => 'web'])
	cd {{ $path }}
	echo "Install composer";
	export COMPOSER_ALLOW_SUPERUSER=1
	composer install --prefer-dist --no-dev --optimize-autoloader
@endtask

@task('yarn_dependencies', ['on' => 'web'])
	#NPM dependencies
	yarn install
	# Grunt
	grunt:clean
    grunt:exec
    grunt:less
@endtask

@task('after_install', ['on' => 'web'])
	echo "Set Deploy mode";
	sudo chown ubuntu:www-data -R generated
	sudo rm -rf var/cache/* generated/metadata/* generated/code/* var/view_preproccessed/* var/page_cache/* pub/static/*

	echo "Clear any previous cached views and optimize the application";
   # php bin/magento dev:source-theme:deploy en_US nl_NL
    php bin/magento setup:static-content:deploy  -f en_US nl_NL
    php bin/magento setup:di:compile
	php bin/magento deploy:mode:set production
    php bin/magento maintenance:enable
    php bin/magento setup:upgrade --keep-generated
	php bin/magento setup:store-config:set --base-url="http://weerman-magento.qa.typeqast.technology/"
    #php bin/magento setup:store-config:set --base-url-secure="https://weerman-magento.qa.typeqast.technology/"
	# Setup use_in_frontend, use_in_backend =1
	# never clear generated while on PRODUCTION MODE
    php bin/magento cache:flush
    php bin/magento cache:clean
	php bin/magento indexer:reindex
	php bin/magento maintenance:disable
@endtask

@task('restart_services', ['on' => 'web'])
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


