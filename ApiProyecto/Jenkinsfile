pipeline {
    agent any

    environment {
        SONARQUBE = 'sonarqube'
    }

    stages {
        stage('Checkout') {
            steps {
                git branch: 'main',
                    url: 'https://github.com/tuusuario/tu-proyecto-laravel.git',
                    credentialsId: 'github-creds'
            }
        }

        stage('Install Dependencies') {
            steps {
                echo '📦 Instalando dependencias...'
                sh 'composer install --no-interaction --prefer-dist'
            }
        }

        stage('Run Tests') {
            steps {
                echo '🧪 Ejecutando pruebas unitarias...'
                sh 'php artisan test --coverage-clover=tests/coverage.xml'
            }
        }

        stage('SonarQube Analysis') {
            steps {
                echo '🚀 Analizando calidad del código...'
                withSonarQubeEnv('sonarqube') {
                    sh '''
                        sonar-scanner \
                            -Dsonar.projectKey=laravel-app \
                            -Dsonar.sources=app,routes,config,database \
                            -Dsonar.exclusions=vendor/**,storage/**,bootstrap/**,node_modules/**,public/** \
                            -Dsonar.php.coverage.reportPaths=tests/coverage.xml \
                            -Dsonar.host.url=http://docker.sonar:9000 \
                            -Dsonar.login=${SONAR_AUTH_TOKEN}
                    '''
                }
            }
        }

        stage('Quality Gate') {
            steps {
                script {
                    timeout(time: 3, unit: 'MINUTES') {
                        waitForQualityGate abortPipeline: true
                    }
                }
            }
        }
    }

    post {
        success {
            echo '✅ Pipeline completado correctamente.'
        }
        failure {
            echo '❌ Falló el pipeline. Revisa logs en Jenkins.'
        }
    }
}

