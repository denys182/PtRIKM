pipeline {
    agent any

    stages {
        stage('Checkout Code') {
            steps {
                checkout scm
            }
        }
        stage('Build') {
            steps {
                sh 'docker build -t my-app:latest .'
            }
        }
        stage('Test') {
            steps {
                
                sh '''
                docker run -d --name test-container my-app:latest
                sleep 5
                docker ps | grep test-container
                docker stop test-container && docker rm test-container
                '''
            }
        }
        stage('Deploy') {
            steps {
                
                sh '''
                docker run -d -p 80:80 --name my-app-container my-app:latest
                '''
            }
        }
    }

    post {
        always {
            echo 'Pipeline завершено.'
        }
        success {
            echo 'Pipeline успішно виконаний.'
        }
        failure {
            echo 'Pipeline завершився з помилкою.'
        }
    }
}
