<?php

/*Use this class to print array data in a table format on shell
  Usage example:
  $res_tbl = new Table();
  $res_tbl->AddColumnNames(array('Iteration', 'ClientThreads', 'TPS','Difference %', 'Failed Requests'));
  $row = array("iter"=>1, "clientThreads"=>200, "TPS"=>10000, "Difference %"=>5%, "failed requests"=>0);
  $res_tbl->AddRow($row);
  $res_tbl->GenerateTable();
*/

class Table{
    private $column_names;
    private $number_of_columns;
    private $column_widths;
    private $total_width;
    private $data_rows;
    private $dash_row;
    private $header_row;
    private $table;

    public function __construct(){
        $this->number_of_columns=0;
        $this->column_widths = array();
        $this->total_width=0;
        $this->table = array();
        $this->data_rows = array();
        $this->dash_row = "";
        $this->header_row = "";
    }
    public function AddColumnNames($column_names){
        $this->column_names = $column_names;
        $this->number_of_columns = count($column_names);
        foreach($column_names as $header){
            $header_length = strlen($header);
            $row = array($header=>$header_length);
            array_push($this->column_widths, $row);
        }
    }

    public function AddRow($row_array){
        array_push($this->data_rows, $row_array);
        $this->CheckColumnWidths($row_array);
    }

    public function GenerateTable(){
        $this->AddRowOfDashes();
        $this->AddRowOfHeaders();
        array_push($this->table, $this->dash_row);     
        $this->AddDataRows();
        array_push($this->table, $this->dash_row);
        $this->PrintTable();
    }

    private function CheckColumnWidths($row_array){
        if(count($row_array) != $this->number_of_columns){
            trigger_error("Number of row elements do not match number of header elements",E_USER_ERROR);
        }
        $ieys = array_keys($row_array);
        for($i=0; $i<$this->number_of_columns; $i++){
            $header_len = $this->column_widths[$i][key($this->column_widths[$i])];
            $row_len = (strlen($row_array[$ieys[$i]]));
            if($row_len > $header_len){
                $this->column_widths[$i][key($this->column_widths[$i])] = $row_len;
            }    
        }
    }

    private function AddRowOfDashes(){
        for($i=0; $i<$this->number_of_columns; $i++){
            $this->total_width += $this->column_widths[$i][key($this->column_widths[$i])];
        }
        $this->total_width += ($this->number_of_columns + 1);
        $this->total_width += ($this->number_of_columns * 2);
        for($j=0; $j<($this->total_width); $j++){
            $this->dash_row .= "-";
        }
        array_push($this->table, $this->dash_row);
    }

    private function AddRowOfHeaders(){
        $num_spaces = 0;
        for($i=0; $i<($this->number_of_columns -1); $i++){
            $this->header_row .= ("| " . $this->column_names[$i]);
            $num_spaces = ($this->column_widths[$i][key($this->column_widths[$i])]) - (strlen($this->column_names[$i]));
            for($j=0; $j<$num_spaces; $j++){
                $this->header_row .= " ";
            }
            $this->header_row .= " ";
        }
        $this->header_row .= "| " . $this->column_names[($this->number_of_columns)-1];
        $num_spaces = ($this->column_widths[$this->number_of_columns - 1][key($this->column_widths[$this->number_of_columns - 1])]) - (strlen($this->column_names[$this->number_of_columns - 1]));
        for($p=0; $p<$num_spaces; $p++){
            $this->header_row .= " ";
        }
        $this->header_row .= " |";  
        array_push($this->table, $this->header_row);
    }

    private function AddDataRows(){
        foreach($this->data_rows as $row){
            $row_keys = array_keys($row);
            $row_array = "";
            for($i=0; $i<((count($row_keys))-1); $i++){
                $row_array .= "| ";
                $row_array .= $row[$row_keys[$i]];
                $num_spaces = ($this->column_widths[$i][key($this->column_widths[$i])]) - ((strlen($row[$row_keys[$i]])));
                for($j=0; $j<$num_spaces; $j++){
                    $row_array .= " ";
                }
                $row_array .= " ";
            }
            
            $row_array .= "| " . $row[$row_keys[(count($row_keys))-1]];
            
            $num_spaces = ($this->column_widths[$this->number_of_columns - 1][key($this->column_widths[$this->number_of_columns - 1])]) - (strlen($row[$row_keys[(count($row_keys))-1]]));
            for($s=0; $s<$num_spaces; $s++){
                $row_array .= " ";
            }
            $row_array .= " |";
            array_push($this->table, $row_array);
        }
    }

    private function PrintTable(){
        foreach($this->table as $final_row){
            echo $final_row . "\n";
        }
    }

    private function isEven($num){
        if(($num % 2) == 0){
            return true;
        }
        else return false;
    }
}