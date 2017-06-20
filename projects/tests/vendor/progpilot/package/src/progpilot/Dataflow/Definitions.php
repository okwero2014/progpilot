<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Dataflow;

use PHPCfg\Block;

class Definitions {

	private $in;
	private $out;
	private $gen;
	private $kill;

	private $defs;
	private $current_func;

	public $blocks;

	public function __construct() 
	{
		$this->current_func = null;
		$this->blocks = new \SplObjectStorage;
	}

	public function printall()
	{
		echo "print gen : \n";
		Definitions::print_stdout($this->gen);
		echo "\n";

		echo "print kill : \n";
		Definitions::print_stdout($this->kill);
		echo "\n";

		echo "print out : \n";
		Definitions::print_stdout($this->out);
		echo "\n";

		echo "print in : \n";
		Definitions::print_stdout($this->in);
		echo "\n";
	}

	public static function printblock($defsparam)
	{
		if($defsparam != null)
		{
			foreach($defsparam as $def)
			{
				$def->print_stdout();
			}
		}
	}

	public static function print_stdout($defsparam)
	{
		if($defsparam != null)
		{
			foreach($defsparam as $blockid => $defs)
			{
				echo "blockid $blockid\n";
				foreach($defs as $def)
				{
					$def->print_stdout();
				}
			}
		}
	}

	public function getdefrefbyname($name)
	{
		if(isset($this->defs[$name]))
			return $this->defs[$name];

		return null;
	}

	public static function is_nearest($lineop, $columnop, $linedef, $columdef)
	{
		if(($lineop > $linedef) || ($lineop == $linedef &&  $columnop >= $columdef))
			return true;

		return false;
	}

	public static function search_force($data, $defsearch)
	{
		$defsfound = [];

		if(count($data) > 0)
		{
			foreach($data as $def)
			{	    
				if($def->get_name() == $defsearch->get_name())
				{
					if(Definitions::is_nearest($defsearch->getLine(), $defsearch->getColumn(), $def->getLine(), $def->getColumn()))
					{
						$defsfound[] = $def;
					}
				}
			}
		}

		return $defsfound;
	}

	public static function search_nearest($line, $column, $data, $defsearch, $inside_class)
	{
		$truedefsfound = [];

		if($data == null)
			return $truedefsfound;

		$defsfound = [];
		foreach($data as $def)
		{
			if($def->get_name() == $defsearch->get_name())
			{
				if(($def->is_instance() && !$def->is_property()) && ($defsearch->is_instance() && !$defsearch->is_property()))
				{		
					$defsfound[$def->get_block_id()][] = [$def, $def->getLine(), $def->getColumn()];
				}

				else if(($def->is_instance() && !$def->is_property()) && $defsearch->is_instance() && $defsearch->is_property())
				{		
					$myclass = $def->get_myinstance()->get_myclass();

					$property = $myclass->get_property($defsearch->get_property_name());
					if($property != null)
					{
						if(($inside_class == null && $property->get_visibility() == "public")
								|| ($inside_class != null && $myclass->get_name() != $inside_class->get_name()))
						{
							$property->set_visibility(true);
						}
						else
							$property->set_visibility(false);

						$defsfound[$def->get_block_id()][] = [$property, $def->getLine(), $def->getColumn()];
					}
				}

				else if(!$def->is_arr() && !$defsearch->is_arr() && !$def->is_property())
				{
					$defsfound[$def->get_block_id()][] = [$def, $def->getLine(), $def->getColumn()];
				}

				else if($def->get_arr_value() == $defsearch->get_arr_value() && $def->is_arr() && $defsearch->is_arr())
				{
					$defsfound[$def->get_block_id()][] = [$def, $def->getLine(), $def->getColumn()];
				}

				else if($def->is_copyarray() && $defsearch->is_arr())
				{
					$copyarrays = $def->get_copyarrays();

					foreach($copyarrays as $value)
					{
						$arrvalue = $value[0];
						$defarr = $value[1];

						if($arrvalue == $defsearch->get_arr_value())
						{
							$defsfound[$def->get_block_id()][] = [$defarr, $def->getLine(), $def->getColumn()];
						}

						unset($defarr);
					}
				}
			}
		}

		// pour gérer le cas de deux ou plus de définitions dans le même bloc (une tainté l'autre non) 
		foreach($defsfound as $blockdefs)
		{
			$nearestdef = null;
			$nearestline = 0;
			$nearestcolumn = 0;

			foreach($blockdefs as $id => $deflast)
			{
				$bisdef = $deflast[0];
				$bisline = $deflast[1];
				$biscolumn = $deflast[2];

				// on prend la plus proche de l'op
				if(Definitions::is_nearest($line, $column, $bisline, $biscolumn))
				{
					if($nearestdef == null || Definitions::is_nearest($bisline, $biscolumn, $nearestline, $nearestcolumn))
					{
						$nearestdef = $bisdef;
						$nearestline = $bisline;
						$nearestcolumn = $biscolumn;
					}
				}

				unset($bisdef);
			}

			$truedefsfound[] = $nearestdef;
		}

		return $truedefsfound;
	} 

	public function getdefs()
	{
		$outputdefs = [];
		foreach($this->defs as $defsblock)
		{
			foreach($defsblock as $def)
			{
				$onedef["name"] = htmlentities($def->get_name(), ENT_QUOTES, 'UTF-8');
				$onedef["tainted"] = $def->get_tainted();
				$onedef["line"] = $def->getLine();

				$outputdefs[] = $onedef;
			}
		}

		$outputjson = array('definitions' => $outputdefs); 
		return $outputjson;
	}

	public function create_block($id)
	{
		$this->in[$id] = [];
		$this->out[$id] = [];
		$this->gen[$id] = [];
		$this->kill[$id] = [];
	}

	/* getters and setters */
	public function adddef($name, &$def)
	{
		$this->defs[$name][] = &$def;

		return true;
	}

	public function addin($block, $def)
	{
		if(isset($this->in[$block]))
		{
			$this->in[$block][] = $def;
			return true;
		}

		return false;
	}

	public function addout($block, $def)
	{
		if(isset($this->out[$block]))
		{
			$this->out[$block][] = $def;
			return true;
		}

		return false;
	}

	public function addgen($block, $def)
	{
		if(isset($this->gen[$block]))
		{
			$this->gen[$block][] = $def;
			return true;
		}

		return false;
	}

	public function addkill($block, $def)
	{
		if(isset($this->kill[$block]))
		{
			$this->kill[$block][] = $def;
			return true;
		}

		return false;
	}

	public function getkill($block)
	{
		if(isset($this->kill[$block]))
			return $this->kill[$block];

		return null;
	}

	public function getgen($block)
	{
		if(isset($this->gen[$block]))
			return $this->gen[$block];

		return null;
	}

	public function getout($block)
	{
		if(isset($this->out[$block]))
			return $this->out[$block];

		return null;
	}

	public function getin($block)
	{
		if(isset($this->in[$block]))
			return $this->in[$block];

		return null;
	}

	public function setkill($block, $kill)
	{
		$this->kill[$block] = $kill;
	}

	public function setgen($block, $gen)
	{
		$this->gen[$block] = $gen;
	}

	public function setout($block, $out)
	{
		$this->out[$block] = $out;
	}

	public function setin($block, $in)
	{
		$this->in[$block] = $in;
	}

	public function get_current_func()
	{
		return $this->current_func;
	}

	public function set_current_func($current_func)
	{
		$this->current_func = $current_func;
	}

	// $this->data["gen"][$blockid]
	public function computekill($blockid)
	{
		foreach($this->gen[$blockid] as $gen)
		{
			$tmpdefs = $this->getdefrefbyname($gen->get_name());
			if($tmpdefs != null)
			{
				foreach($tmpdefs as &$def)
				{
					$this->kill[$blockid][] = &$def;
				}
			}
		}
	}

	public function getBlockId($block) {

		if (isset($this->blocks[$block])) 
			return $this->blocks[$block];

		return null;
	}

	public function reachingDefs(&$myblocks)
	{
		foreach($myblocks as $id => $block)
			$this->setout($id, $this->getgen($id));

		$change = true;

		while($change)
		{
			$change = false;

			foreach($myblocks as $id => $block)
			{
				foreach($block->parents as $idparent => $parent)
				{
					$idcurrent = $block->get_id();
					$idparent = $parent->get_id();

					$temp = ArrayMulti::array_merge_multi($this->getin($idcurrent), $this->getout($idparent));
					$this->setin($idcurrent, $temp);

					$oldout = $this->getout($idcurrent);

					$inminus = ArrayMulti::array_minus_multi($this->getin($idcurrent), $this->getkill($idcurrent));
					$this->setout($idcurrent, ArrayMulti::array_merge_multi($this->getgen($idcurrent), $inminus));

					if($this->getout($idcurrent) != $oldout)
						$change = true;
				}
			}
		}
	}
}

?>