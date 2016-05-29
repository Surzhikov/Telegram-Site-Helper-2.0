<?php
header('Content-Type: text/html; charset=utf-8'); 
mb_internal_encoding("UTF-8");

	function sendMessage($botToken,$chatId,$message){
		$telegramurl = "https://api.telegram.org/bot".$botToken."/sendMessage";
		$request = curl_init($telegramurl);
		curl_setopt($request, CURLOPT_POST, true);
		$query=array('chat_id' => $chatId, "text"=>$message);
		curl_setopt($request, CURLOPT_POSTFIELDS, $query);
		curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
		$result = curl_exec($request);
		curl_close($request);
		return($result);
	}

	require_once("telegram-site-helper-config.php");
	$input=file_get_contents("php://input");

	if(DBTYPE=="mysql"){
		try{
			$db = new PDO('mysql:host='.MYSQL_HOST.';dbname='.MYSQL_DBNAME,MYSQL_USER,MYSQL_PASSWORD);
			$db->exec('set names utf8');
		}catch(PDOException $e){}
	}else{
		try{
			$db = new PDO("sqlite:".SQLITE_DBNAME);
			$db->exec('PRAGMA journal_mode=WAL;');
		}catch(PDOException $e){}
	}
	try{
		@$update=json_decode($input,true);
	}catch(Exception $e){}

	if(!is_array($update)){
		exit();
	}

	if(!array_key_exists("message", $update)){
		exit();
	}

	$telegramId=$update["message"]["from"]["id"];
	$msgTime=$update["message"]["date"];
	$managerName=$update["message"]["from"]["first_name"]." ".$update["message"]["from"]["last_name"];
	
	if(array_key_exists("text", $update["message"])){
		$msgText=$update["message"]["text"];
	}



	$sth=$db->prepare("SELECT managerId, managerNowChat FROM telegramSiteHelperManagers WHERE managerTelegramId=:managerTelegramId");
	var_dump($db->errorInfo());
	$sth->execute(array(":managerTelegramId"=>$telegramId));
	$answer=$sth->fetch();

	if($answer!=false){
		$managerId=$answer["managerId"];
		$managerNowChat=$answer["managerNowChat"];
	}else{
		$managerId=null;
		$managerNowChat=null;
	}

	if($managerId!=null){
		/* Авторизованный менеджер */

		if($msgText=="/commands"){

			sendMessage(BOTTOKEN,$telegramId,"Список команд:\n/offline - статус офлайн (НЕ принимать сообщения от новых клиентов)\n/online - статус онлайн (принимать сообщения от новых клиентов)\n/logout - Удалить себя из системы\n/chat_ID - перейти в чат для общения с клиентом (вместо ID - идентификатор чата)\n/hystory_ID - получить историю сообщений чата (вместо ID - идентификатор чата)\n/newname Name - смена имени менеджера в чате");

		}elseif($msgText=="/offline"){

			$sth=$db->prepare("UPDATE telegramSiteHelperManagers SET managerStatus=:managerStatus WHERE managerTelegramId=:managerTelegramId");
			$sth->execute(array(":managerTelegramId"=>$telegramId, ":managerStatus"=>0));
			sendMessage(BOTTOKEN,$telegramId,json_decode('"\u26d4"'). " Вы оффлайн и не будете получать сообщения от новых пользователей");

		}elseif($msgText=="/online"){

			$sth=$db->prepare("UPDATE telegramSiteHelperManagers SET managerStatus=:managerStatus WHERE managerTelegramId=:managerTelegramId");
			$sth->execute(array(":managerTelegramId"=>$telegramId, ":managerStatus"=>1));
			sendMessage(BOTTOKEN,$telegramId,json_decode('"\u2705"')." Вы снова онлайн и будете получать сообщения от новых пользователей");

		}elseif($msgText=="/logout"){

			$sth=$db->prepare("UPDATE telegramSiteHelperManagers SET managerTelegramId=:managerTelegramId2 WHERE managerTelegramId=:managerTelegramId");
			$sth->execute(array(":managerTelegramId"=>$telegramId,":managerTelegramId2"=>null));
			sendMessage(BOTTOKEN,$telegramId,"Вы вышли из системы");

		}elseif(mb_substr($msgText,0,6)=="/chat_"){

			$chatId=trim(mb_substr($msgText,6));
			$sth=$db->prepare("SELECT count(*) as count FROM telegramSiteHelperChats WHERE chatId=:chatId");
			$sth->execute(array(":chatId"=>$chatId));
			$answer=$sth->fetch();
			if($answer["count"]>0){
				$sth=$db->prepare("UPDATE telegramSiteHelperManagers SET managerNowChat=:managerNowChat WHERE  managerId =:managerId");
				$sth->execute(array(":managerNowChat"=>$chatId, ":managerId"=>$managerId));
				sendMessage(BOTTOKEN,$telegramId,json_decode('"\u2714"')." Вы перешли в чат /chat_".$chatId."");

				$sth=$db->prepare("SELECT chatCustomerName, chatCustomerPhone FROM telegramSiteHelperChats WHERE chatId=:chatId");
				$sth->execute(array(":chatId"=>$chatId));
				$answer=$sth->fetch();
				if($answer["chatCustomerName"]!=null || $answer["chatCustomerPhone"]!=null){
					sendMessage(BOTTOKEN,$telegramId,json_decode('"\ud83d\udc64"')." Клиент: ".$answer["chatCustomerName"]." ".$answer["chatCustomerPhone"]."\nДля вывода полной истории сообщений нажмите /hystory_".$chatId);
				}

			}else{
				sendMessage(BOTTOKEN,$telegramId,json_decode('"\ud83d\udeab"')." Чат /chat_".$chatId." не существует");
			}

			

		}elseif(mb_substr($msgText,0,9)=="/hystory_"){

			$chatId=trim(mb_substr($msgText,9));
			$sth=$db->prepare("SELECT count(*) as count FROM telegramSiteHelperChats WHERE chatId=:chatId");
			$sth->execute(array(":chatId"=>$chatId));
			$answer=$sth->fetch();
			$hystory="История переписки /chat_".$chatId."\n\n";
			if($answer["count"]>0){

				$sth=$db->prepare("SELECT chatCustomerName, chatCustomerPhone FROM telegramSiteHelperChats WHERE chatId=:chatId");
				$sth->execute(array(":chatId"=>$chatId));
				$answer=$sth->fetch();
				if($answer["chatCustomerName"]!=null){
					$chatCustomerName=$answer["chatCustomerName"];
				}else{
					$chatCustomerName="Клиент";
				}
				

				$sth=$db->prepare("SELECT telegramSiteHelperMessages.msgTime, telegramSiteHelperMessages.msgText, telegramSiteHelperManagers.managerName FROM telegramSiteHelperMessages LEFT JOIN telegramSiteHelperManagers ON telegramSiteHelperMessages.msgFrom=telegramSiteHelperManagers.managerId WHERE msgChatId=:msgChatId ORDER BY msgTime");
				$sth->execute(array(":msgChatId"=>$chatId));
				while($answer=$sth->fetch()){
					$msg="\n";
					$msg.=date("j.m (H:i:s)",$answer["msgTime"])."\n";
					if($answer["managerName"]!=null){
						$msg.=json_decode('"\ud83d\udde3"')." Менеджер ".$answer["managerName"].":\n";
					}else{
						$msg.=json_decode('"\ud83d\udde3"')." ".$chatCustomerName.":\n";
					}

					$msgTextHystory=json_decode($answer["msgText"],true);
					if(array_key_exists("text", $msgTextHystory)){
						$msg.="- ".$msgTextHystory["text"]."\n";
					}else if(array_key_exists("photo", $msgTextHystory)){
						$msg.="- [photo]\n";
					}else if(array_key_exists("file", $msgTextHystory) && array_key_exists("filename", $msgTextHystory)){
						$msg.="- [file: ".$msgTextHystory["filename"]."]\n";
					}
					$hystory.=$msg;
				}
				sendMessage(BOTTOKEN,$telegramId,$hystory);
			}else{
				sendMessage(BOTTOKEN,$telegramId,json_decode('"\ud83d\udeab"')." Чат /chat_".$chatId." не существует");
			}

		}elseif(mb_substr($msgText,0,8)=="/newname"){

			$newName=trim(mb_substr($msgText,8));
			if($newName==null){
				sendMessage(BOTTOKEN,$telegramId,"Необходимо ввести хотя бы один символ. Имя не изменено.");
			}else{
				$sth=$db->prepare("UPDATE telegramSiteHelperManagers SET managerName=:managerName WHERE managerTelegramId=:managerTelegramId");
				$sth->execute(array(":managerTelegramId"=>$telegramId, ":managerName"=>$newName));
				sendMessage(BOTTOKEN,$telegramId,"Ваше новое имя в чате: ".$newName);
			}

		}else if($managerNowChat!=null){

			if(array_key_exists("text", $update["message"])){
				$msgContent=json_encode(array("text"=>$update["message"]["text"]));
			}else if(array_key_exists("photo", $update["message"])){
				$photo=$update["message"]["photo"][(count($update["message"]["photo"])-1)]["file_id"];
				$msgContent=json_encode(array("photo"=>$photo));
			}else if(array_key_exists("document", $update["message"])){
				$fileId=$update["message"]["document"]["file_id"];
				$fileName=$update["message"]["document"]["file_name"];
				$msgContent=json_encode(array("file"=>$fileId, "filename"=>$fileName));
			}else{
				$msgContent=null;
			} 

			$sth=$db->prepare("INSERT INTO telegramSiteHelperMessages (msgChatId, msgFrom, msgTime, msgText) VALUES (:msgChatId, :msgFrom, :msgTime, :msgText)");
			$sth->execute(array(
				":msgChatId"=>$managerNowChat,
				":msgFrom"=>$managerId,
				":msgTime"=>$msgTime,
				":msgText"=>$msgContent)
			);
			$fpTime=fopen("tsh-chatUpdates/".$managerNowChat.".update","w");
			fwrite($fpTime, microtime(true));
			fclose($fpTime);
		}else{
			sendMessage(BOTTOKEN,$telegramId,json_decode('"\ud83d\udcac"')." Выберите чат, в который отправить сообщение! Введите \"/chat_IdЧата\" или нажмите на ссылку в конце сообщения пользователя.");
		
		}
	}else{
		/* Некто не авторизованный*/
		if(mb_substr($msgText,0,6)=="/login"){
			$password=trim(mb_substr($msgText,6));
			if($password==MANAGERPASS){
				$sth=$db->prepare("INSERT INTO telegramSiteHelperManagers (managerTelegramId, managerName, managerNowChat, managerStatus) VALUES (:managerTelegramId, :managerName, :managerNowChat, :managerStatus)");
				$sth->execute(array(
					":managerTelegramId"=>$telegramId,
					":managerName"=>$managerName,
					":managerNowChat"=>null,
					":managerStatus"=>1
					)
				);
				sendMessage(BOTTOKEN,$telegramId,"Пароль верный. Вы вошли в систему.");
				sendMessage(BOTTOKEN,$telegramId,"Ваш статус - /online. Для отключения введите /offline\nЧтобы удалить себя из системы введите /logout");
				sendMessage(BOTTOKEN,$telegramId,"Ваш имя: ".$managerName.". Если хотите сменить именя в чате - введите \"/newname Новое Имя\"");
			}else{
					sendMessage(BOTTOKEN,$telegramId,"Пароль не верный. Уточните пароль менеджера у администратора системы");
			}
		}else{
			sendMessage(BOTTOKEN,$telegramId,json_decode('"\ud83d\udd10"')." Для авторизации введите \"/login пароль_менеджера\"");
		}
	}
