<?php

require_once 'TelegramBot.php';

class PollBot extends TelegramBot {

  public $redis = false;

  protected static $REDIS_HOST = '127.0.0.1';
  protected static $REDIS_PORT = 6379;

  public function init() {
    parent::init();
    $this->dbInit();
  }

  public function dbInit() {
    if (!$this->redis) {
      $this->redis = new Redis();
      $redis_connected = $this->redis->connect(self::$REDIS_HOST, self::$REDIS_PORT);
      if (!$redis_connected) {
        throw new Exception("Redis not connected");
      }
    }
  }
}

class PollBotChat extends TelegramBotChat {

  protected $redis;

  protected $curPoll = false;
  protected static $optionsLimit = 10;

  public function __construct($core, $chat_id) {
    parent::__construct($core, $chat_id);
    $this->redis = $this->core->redis;
  }

  public function init() {
    $this->curPoll = $this->dbGetPoll();
  }



  public function command_start($params, $message) {
    if (!$this->isGroup) {
      $this->command_newpoll('', $message);
    } else {
      $poll_id = $params;
      $poll = $this->dbGetPollById($poll_id);
      if ($poll) {
        if ($this->curPoll) {
          $this->sendOnePollOnly();
        } else {
          if ($poll['author_id'] == $message['from']['id']) {
            $this->dbSavePoll($poll);
            $this->curPoll = $poll;
            $this->sendPoll();
          }
        }
      }
    }
  }

  public function command_newpoll($params, $message) {
    if ($this->curPoll && $this->isGroup) {
      if ($this->isGroup) {
        $this->sendOnePollOnly();
        return;
      } else {
        $this->dbDropPoll();
        $this->curPoll = false;
      }
    }

    $author_id = $message['from']['id'];
    $message_id = $message['message_id'];
    $newpoll = $this->parsePollParams($params);

    $has_title = strlen($newpoll['title']) > 0;
    $has_options = count($newpoll['options']) > 0;

    if ($has_title && $has_options) {
      $this->createPoll($author_id, $newpoll);
    } else if ($has_title) {
      $this->needPollOptions($author_id, $newpoll, $message_id);
    } else {
      $this->needPollTitle($author_id, $message_id);
    }
  }

  public function command_poll($params, $message) {
    if (!$this->isGroup) {
      return $this->sendGroupOnly();
    }
    if (!$this->curPoll) {
      return $this->sendNoPoll();
    }

    $this->sendPoll(true, $message['message_id']);
  }

  public function command_results($params, $message) {
    if (!$this->isGroup) {
      return $this->sendGroupOnly();
    }
    if (!$this->curPoll) {
      return $this->sendNoPoll();
    }

    $this->sendResults();
  }

  public function command_endpoll($params, $message) {
    if (!$this->isGroup) {
      return $this->sendGroupOnly();
    }
    if (!$this->curPoll) {
      return $this->sendNoPoll();
    }

    $this->sendResults(true);

    $this->dbDropPoll();
    $this->curPoll = false;
  }

  public function command_done($params, $message) {
    $author_id = $message['from']['id'];
    $newpoll = $this->dbGetPollCreating($author_id);
    if (!$newpoll) {
      $this->sendHelp();
    } else if ($this->curPoll) {
      $this->dbDropPollCreating($author_id);
      $this->sendOnePollOnly();
    } else {
      $this->donePollCreating($author_id, $newpoll, $message['message_id']);
    }
  }

  public function command_help($params, $message) {
    $this->sendHelp();
  }

  public function bot_added_to_chat($message) {
    $this->sendHelp();
  }

  public function some_command($command, $params, $message) {
    $option_num = intval($command);
    if ($option_num > 0) {
      if (!$this->isGroup) {
        return $this->sendGroupOnly();
      }
      if (!$this->curPoll) {
        return $this->sendNoPoll();
      }

      $option_id = $option_num - 1;
      $options_count = count($this->curPoll['options']);
      if ($option_id >= 0 && $option_id < $options_count) {
        $this->pollNewVote($message['from'], $option_id, $message['message_id']);
      }
    } else {
      $this->sendHelp();
    }
  }

  public function message($text, $message) {
    if ($this->curPoll && $this->isGroup) {
      $option = trim($text);
      $option_id = array_search($option, $this->curPoll['options'], true);
      if ($option_id !== false) {
        $this->pollNewVote($message['from'], $option_id, $message['message_id']);
      }
    } else {
      $author_id = $message['from']['id'];
      $newpoll = $this->dbGetPollCreating($author_id);
      if ($newpoll) {
        if ($this->curPoll) {
          $this->dbDropPollCreating($author_id);
          $this->sendOnePollOnly();
          return;
        }
        if ($newpoll['state'] == 'need_title') {
          $title = trim($text);
          $title = str_replace("\n", ' ', $title);
          $title = mb_substr($title, 0, 1024, 'UTF-8');
          if (!strlen($title)) {
            $this->apiSendMessage("Sorry, I only support text and emoji for questions and answers.");
            return;
          }
          $newpoll['title'] = $title;
          $this->needPollOptions($author_id, $newpoll, $message['message_id']);
        } else if ($newpoll['state'] == 'need_options') {
          $option = trim($text);
          $option = str_replace("\n", ' ', $option);
          $option = mb_substr($option, 0, 256, 'UTF-8');
          if (!strlen($option)) {
            $this->apiSendMessage("Sorry, I only support text and emoji for questions and answers.");
            return;
          }
          if (!in_array($option, $newpoll['options'], true)) {
            $newpoll['options'][] = $option;
          }
          if (count($newpoll['options']) < self::$optionsLimit) {
            $this->needPollOptions($author_id, $newpoll, $message['message_id']);
          } else {
            $this->createPoll($author_id, $newpoll);
          }
        }
      }
    }
  }



  protected function parsePollParams($params) {
    $params = explode("\n", $params);
    $params = array_map('trim', $params);
    $params = array_filter($params);
    $params = array_unique($params);

    $title = array_shift($params);
    $title = mb_substr($title, 0, 1024, 'UTF-8');

    $options = array_slice($params, 0, self::$optionsLimit);
    foreach ($options as &$option) {
      $option = mb_substr($option, 0, 256, 'UTF-8');
    }

    return array(
      'title' => $title,
      'options' => $options,
    );
  }

  protected function needPollTitle($author_id, $message_id) {
    $newpoll = array(
      'state' => 'need_title',
    );
    $this->dbSavePollCreating($author_id, $newpoll);

    $text = "Let's create a new poll. First, send me the question.";
    if ($this->isGroup) {
      $params = array(
        'reply_markup' => array(
          'force_reply_keyboard' => true,
          'selective' => true,
        ),
        'reply_to_message_id' => $message_id,
      );
    } else {
      $params = array(
        'reply_markup' => array(
          'hide_keyboard' => true,
        ),
      );
    }
    $this->apiSendMessage($text, $params);
  }

  protected function needPollOptions($author_id, $newpoll, $message_id) {
    if (!isset($newpoll['options'])) {
      $newpoll['options'] = array();
    }
    $newpoll['state'] = 'need_options';
    $this->dbSavePollCreating($author_id, $newpoll);

    if (count($newpoll['options']) > 0) {
      $text = "Good. Now send me another answer option.\n\nWhen you've added enough options, simply send /done to publish the poll.";
    } else {
      $text = "Creating a new poll: '{$newpoll['title']}'\n\nPlease send me the first answer option.";
    }
    if ($this->isGroup) {
      $params = array(
        'reply_markup' => array(
          'force_reply_keyboard' => true,
          'selective' => true,
        ),
        'reply_to_message_id' => $message_id,
      );
    } else {
      $params = array(
        'reply_markup' => array(
          'hide_keyboard' => true,
        ),
      );
    }
    $this->apiSendMessage($text, $params);
  }

  protected function donePollCreating($author_id, $newpoll, $message_id = 0) {
    $has_title = strlen($newpoll['title']) > 0;
    $has_options = count($newpoll['options']) > 0;

    if ($has_title && $has_options) {
      $this->createPoll($author_id, $newpoll);
    } else {
      $this->dbDropPollCreating($author_id);
      $this->apiSendMessage("Sorry, a poll needs to have a question and at least one answer option to work. Send /newpoll to try again.");
    }
  }

  protected function createPoll($author_id, $newpoll) {
    $poll = array(
      'title' => $newpoll['title'],
      'options' => $newpoll['options'],
      'author_id' => $author_id,
    );

    if ($this->isGroup) {
      $this->dbSavePoll($poll);
      $this->curPoll = $poll;
      $poll_id = true;
    } else {
      $this->curPoll = false;
      $poll = $this->dbSavePollById($poll);
      $this->dbDropPollCreating($author_id);
    }

    $this->sendPollCreated($poll);
  }

  protected function pollNewVote($voter, $option_id, $message_id = 0) {
    $chat_id = $this->chatId;
    $voter_id = $voter['id'];

    $message_params = array(
      'reply_markup' => array(
        'hide_keyboard' => true,
        'selective' => true,
      ),
    );
    if ($voter['username']) {
      $name = ' @'.$voter['username'];
    } else {
      $name = $voter['first_name'];
      $message_params['reply_to_message_id'] = $message_id;
    }

    $option = $this->curPoll['options'][$option_id];
    $already_voted = $this->dbCheckOption($voter_id, $option_id);
    if ($already_voted) {
      $text = "☝️{$name} is still for '{$option}'.";
    } else {
      $new_vote = $this->dbSelectOption($voter_id, $option_id);
      if ($new_vote) {
        $text = "☝️{$name} voted for '{$option}'.";
      } else {
        $text = "☝️{$name} changed the vote to '{$option}'.";
      }
    }
    $text .= "\n/results - show results\n/poll - repeat the question";

    $this->apiSendMessage($text, $message_params);
  }



  protected function getPollText($poll, $plain = false) {
    $text = $poll['title']."\n";
    foreach ($poll['options'] as $i => $option) {
      if ($plain) {
        $text .= "\n".($i + 1).". {$option}";
      } else {
        $text .= "\n/".($i + 1).". {$option}";
      }
    }
    return $text;
  }

  protected function getPollKeyboard() {
    $keyboard = array();
    foreach ($this->curPoll['options'] as $option) {
      $keyboard[] = array($option);
    }
    return $keyboard;
  }

  protected function getPollLink($poll_id) {
    $username = strtolower($this->core->botUsername);
    return "telegram.me/{$username}?startgroup={$poll_id}";
  }



  protected function dbGetPoll() {
    $poll_str = $this->redis->get('c'.$this->chatId.':poll');
    if (!$poll_str) {
      return false;
    }
    return json_decode($poll_str, true);
  }

  protected function dbSavePoll($poll) {
    $poll_str = json_encode($poll);
    $this->redis->set('c'.$this->chatId.':poll', $poll_str);
  }

  protected function dbGetPollById($poll_id) {
    $poll_str = $this->redis->get('poll:'.$poll_id);
    if (!$poll_str) {
      return false;
    }
    return json_decode($poll_str, true);
  }

  protected function dbSavePollById($poll) {
    $poll_str = json_encode($poll);
    $tries = 0;
    do {
      $poll_id = md5($poll_str.'#'.$tries);
      $result = $this->redis->setnx('poll:'.$poll_id, $poll_str);
      if ($result) {
        break;
      }
    } while (++$tries < 100);

    $poll['id'] = $poll_id;
    return $poll;
  }

  protected function dbDropPoll() {
    $keys = array(
      'c'.$this->chatId.':poll',
      'c'.$this->chatId.':members',
    );
    for ($i = 0; $i < self::$optionsLimit; $i++) {
      $keys[] = 'c'.$this->chatId.':o'.$i.':members';
    }
    $this->redis->delete($keys);
  }

  protected function dbCheckOption($voter_id, $option_id) {
    $chat_id = $this->chatId;
    return $this->redis->sIsMember('c'.$chat_id.':o'.$option_id.':members', $voter_id);
  }

  protected function dbSavePollCreating($author_id, $poll) {
    $chat_id = $this->chatId;
    $this->redis->set("newpoll{$chat_id}:{$author_id}", json_encode($poll));
  }

  protected function dbGetPollCreating($author_id) {
    $chat_id = $this->chatId;
    $poll = json_decode($this->redis->get("newpoll{$chat_id}:{$author_id}"), true);
    return $poll;
  }

  protected function dbDropPollCreating($author_id) {
    $chat_id = $this->chatId;
    return $this->redis->delete("newpoll{$chat_id}:{$author_id}");
  }

  protected function dbSelectOption($voter_id, $option_id) {
    $chat_id = $this->chatId;
    $redis = $this->redis->multi();
    $redis->sAdd('c'.$chat_id.':members', $voter_id);

    $options_count = count($this->curPoll['options']);
    for ($i = 0; $i < $options_count; $i++) {
      if ($i == $option_id) {
        $redis->sAdd('c'.$chat_id.':o'.$i.':members', $voter_id);
      } else {
        $redis->sRem('c'.$chat_id.':o'.$i.':members', $voter_id);
      }
    }
    $result = $redis->exec();
    $added = array_shift($result);
    return $added;
  }



  protected function sendGreeting() {
    $this->apiSendMessage("To create a new poll, send me a message exactly in this format:\n\n/newpoll\nYour question\nAnswer option 1\nAnswer option 2\n...\nAnswer option x");
  }

  protected function sendGroupOnly() {
    $this->apiSendMessage("This command will work in those of your groups that have an active poll. Use /newpoll to create a poll.");
  }

  protected function sendNoPoll() {
    $this->apiSendMessage("No active polls in this group. Use /newpoll to create a poll first.");
  }

  protected function sendOnePollOnly() {
    $this->apiSendMessage("Sorry, only one poll at a time is allowed.\n/poll - repeat the question\n/endpoll - close current poll");
  }

  protected function sendHelp() {
    if ($this->isGroup) {
      $text = "This bot can create simple polls in groups.";
    } else {
      $text = "This bot can create simple polls. You can create a poll and share it to a group.";
    }
    $text .= "\n\n/newpoll - create a poll\n/results - see how the poll is going\n/poll - repeat the question\n/endpoll - close poll and show final results";
    $this->apiSendMessage($text);
  }

  public function sendPoll($resend = false, $message_id = 0) {
    $text = $this->getPollText($this->curPoll);
    if ($this->isGroup) {
      $text .= "\n\n/results - show results\n/endpoll - close poll";
    }
    $message_params = array(
      'reply_markup' => array(
        'keyboard' => $this->getPollKeyboard(),
      ),
    );
    if ($resend && $this->isGroup) {
      $options['reply_markup']['selective'] = true;
      $options['reply_to_message_id'] = $message_id;
    }
    $this->apiSendMessage($text, $message_params);
  }

  protected function sendPollCreated($poll) {
    $text = "👍 Poll created.";
    if (!$this->isGroup) {
      $text .= " Use this link to share it to a group:\n";
      $text .= $this->getPollLink($poll['id']);
      $text .= "\n\n";
      $text .= $this->getPollText($poll, true);
    }
    $this->apiSendMessage($text);

    if ($this->isGroup) {
      $this->sendPoll();
    }
  }

  protected function sendResults($final = false) {
    $results = array();
    $total_value = 0;
    $max_value = 0;
    foreach ($this->curPoll['options'] as $i => $option) {
      $value = intval($this->redis->sCard('c'.$this->chatId.':o'.$i.':members'));
      $total_value += $value;
      $max_value = max($max_value, $value);
      $results[] = array(
        'label' => $option,
        'value' => $value,
      );
    }
    foreach ($results as &$result) {
      $result['pc'] = $max_value ? round($result['value'] * 7 / $max_value) : 0;
      $result['procent'] = $total_value ? round($result['value'] * 100 / $total_value) : 0;
    }
    uasort($results, function($a, $b) { return ($b['value'] - $a['value']); });

    $text = '';
    if ($final) {
      $text .= "📊 Poll closed, final results:\n\n";
    }
    $text .= $this->curPoll['title']."\n";
    if (!$total_value) {
      $text .= "👥 Nobody";
    } else if ($total_value == 1) {
      $text .= "👥 1 person";
    } else {
      $text .= "👥 {$total_value} people";
    }
    if ($final) {
      $text .= " voted in total.";
    } else {
      $text .= " voted so far.";
    }
    foreach ($results as &$result) {
      $text .= "\n\n{$result['label']} – {$result['value']}\n";
      $text .= ($result['pc'] ? str_repeat('👍', $result['pc']) : '▫️');
      $text .= " {$result['procent']}%";
    }
    if (!$final) {
      $text .= "\n\n/poll - repeat question\n/endpoll - close poll";
    }

    $message_params = array();
    if ($final) {
      $message_params['reply_markup'] = array(
        'hide_keyboard' => true,
      );
    }

    $this->apiSendMessage($text, $message_params);
  }
}
