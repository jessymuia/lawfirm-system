# Installation

## Prerequisites
1. Node js and npm installed on the server
```sudo apt update```   
```sudo apt install nodejs npm```
```npm i vue```
    ### Confirm Installation
   ```node -v```
   ```npm -v```
2. PHP Installed in the server
3. MySQL installed in the server 

## Step 1: Clone the Repository
    git clone <repo url>

## Step 2: Install Dependencies
    cd <project_repo>
    npm install
    composer install

## Step 3: Configure Environment Variables
    cp .env.example .env
    1. Make sure the mail server is set-up
    2. Change APP_ENV to  production

## Step 4: Build Assets
    npm run prod

## Step 5: Setup Database
    php artisan app:init
    1. Make sure the email in the command CreateAdmin is the admin email

## Step 6: Configure Web Server

    

