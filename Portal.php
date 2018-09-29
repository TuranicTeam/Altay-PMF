<?php

/*
 *               _ _
 *         /\   | | |
 *        /  \  | | |_ __ _ _   _
 *       / /\ \ | | __/ _` | | | |
 *      / ____ \| | || (_| | |_| |
 *     /_/    \_|_|\__\__,_|\__, |
 *                           __/ |
 *                          |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author TuranicTeam
 * @link https://github.com/TuranicTeam/Altay
 *
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\PillarRotationTrait;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\Player;

class Portal extends Flowable{

	use PillarRotationTrait;

	protected $id = self::PORTAL;

	public $axis = Facing::AXIS_X;

	public function __construct(){

	}

	public function getName() : string{
		return "Nether Portal";
	}

	public function getHardness() : float{
		return -1;
	}

	public function getBlastResistance() : float{
		return 0;
	}

	public function getLightLevel() : int{
		return 11;
	}

	public function isBreakable(Item $item) : bool{
		return false;
	}

	public function onBreak(Item $item, Player $player = null) : bool{
		$result = parent::onBreak($item, $player);

		foreach($this->getAllSides() as $side){
			if($side instanceof Portal){
				$side->onBreak($item, $player);
			}
		}

		return $result;
	}
}