<?php

set_time_limit(0);

require_once 'PollBot.php';

define('BOT_TOKEN', 'XXXXXXXXX:XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');

$bot = new PollBot(BOT_TOKEN, 'PollBotChat');
$bot->runLongpoll();
