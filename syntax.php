<?php
/**
 * Bureaucracy Plugin: Creates forms and submits them via email
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_bureaucracy extends DokuWiki_Syntax_Plugin {
    // allowed types and the number of arguments
    var $argcheck = array(
                        'textbox'    => 2,
                        'email'      => 2,
                        'password'   => 2,
                        'number'     => 2,
                        'submit'     => 1,
                        'fieldset'   => 1,
                        'select'     => 3,
                        'onoff'      => 2,
                        'yesno'      => 2,
                        'static'     => 2,
                        'textarea'   => 2,
                        'action'     => 2,
                        'thanks'     => 2,
                        );
    // types that are no fields
    var $nofield = array('action','static','fieldset','submit','thanks');

    /**
     * return some info
     */
    function getInfo(){
        return array(
            'author' => 'Andreas Gohr',
            'email'  => 'andi@splitbrain.org',
            'date'   => '2008-09-23',
            'name'   => 'Bureaucracy Plugin',
            'desc'   => 'A simple form generator/emailer',
            'url'    => 'http://dokuwiki.org/plugin:bureaucracy',
        );
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'block';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 155;
    }


    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<form>.*?</form>',$mode,'plugin_bureaucracy');
    }


    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){
        $match = substr($match,6,-7); // remove form wrap
        $lines = explode("\n",$match);
        $action = '';
        $thanks = 'Data sent.';

        // parse the lines into an command/argument array
        $cmds = array();
        foreach($lines as $line){
            $line = trim($line);
            if(!$line) continue;
            $args = $this->_parse_line($line);
            $args[0] = strtolower($args[0]);
            if(!isset($this->argcheck[$args[0]])){
                msg('Unknown type "'.hsc($args[0]).'"',-1);
                continue;
            }
            if(count($args) < $this->argcheck[$args[0]]){
                msg('Not enough arguments for '.hsc($args[0]).' '.hsc($args[1]),-1);
                continue;
            }

            // is action element?
            if($args[0] == 'action'){
                $action = $args[1];
                continue;
            }

            // is thank you text?
            if($args[0] == 'thanks'){
                $thanks = $args[1];
                continue;
            }

            // get standard arguments
            $opt = array();
            $opt['cmd']   = array_shift($args);
            $opt['label'] = array_shift($args);
            $opt['idx']   = base64_encode($opt['label']);

            // save addtional minimum args here
            $keep = $this->argcheck[$opt['cmd']]-2;
            if($keep > 0){
                $opt['args'] = array_slice($args,0,$keep);
            }

            // parse additional arguments
            foreach($args as $arg){
                if($arg[0] == '='){
                    $opt['default'] = substr($arg,1);
                }elseif($arg[0] == '>'){
                    $opt['min'] = substr($arg,1);
                    if(!is_numeric($opt['min'])) unset($opt['min']);
                }elseif($arg[0] == '<'){
                    $opt['max'] = substr($arg,1);
                    if(!is_numeric($opt['max'])) unset($opt['max']);
                }elseif($arg[0] == '/' && substr($arg,-1) == '/'){
                    $opt['re'] = substr($arg,1,-1);
                }elseif($arg == '!'){
                    $opt['optional'] = true;
                }
            }


            $cmds[] = $opt;
        }

        if(!$action){
            msg('No action defined - there is no target to send the data to',-1);
        }

        return array('data'=>$cmds,'action'=>$action,'thanks'=>$thanks);
    }

    /**
     * Create output
     */
    function render($format, &$R, $data) {
        global $ID;
        if($format != 'xhtml') return false;

        $errors = array();
        if(isset($_POST['bureaucracy'])){
            $errors = $this->_checkpost($data['data']);
            if(!count($errors) && $data['action']){
                if($this->_sendemail($data['data'],$data['action'])){
                    $R->doc .= '<p>'.hsc($data['thanks']).'</p>';
                    return true;
                }else{
                    msg('Something went wrong with sending that data',-1);
                }
            }
        }
        $R->doc .= $this->_htmlform($data['data'],$errors);

        return true;
    }

    /**
     * Validate any posted data, display errors using the msg() function,
     * put a list of bad fields in the return array
     */
    function _checkpost($data){
        $errors = array();

        foreach($data as $opt){
            // required
            if(trim($_POST['bureaucracy'][$opt['idx']]) === ''){
                if($opt['optional']) continue;
                if(in_array($opt['cmd'],$this->nofield)) continue;
                $errors[$opt['idx']] = 1;
                msg(hsc($opt['label']).' has to be filled',-1);
                continue;
            }
            $value = $_POST['bureaucracy'][$opt['idx']];

            // regexp
            if($opt['re'] && !@preg_match('/'.$opt['re'].'/i',$value)){
                $errors[$opt['idx']] = 1;
                msg(hsc($opt['label']).' isn\'t valid (matched against /'.hsc($opt['re']).'/i)',-1);
                continue;
            }

            // email
            if($opt['cmd'] == 'email' && !mail_isvalid($value)){
                $errors[$opt['idx']] = 1;
                msg(hsc($opt['label']).' needs to be a valid email address',-1);
                continue;
            }

            // numbers
            if($opt['cmd'] == 'number' && !is_numeric($value)){
                $errors[$opt['idx']] = 1;
                msg(hsc($opt['label']).' has to be numeric',-1);
                continue;
            }

            // min
            if(isset($opt['min']) && $value < $opt['min']){
                $errors[$opt['idx']] = 1;
                msg(hsc($opt['label']).' has to be at least '.hsc($opt['min']),-1);
                continue;
            }

            // max
            if(isset($opt['max']) && $value > $opt['max']){
                $errors[$opt['idx']] = 1;
                msg(hsc($opt['label']).' has to be lower than '.hsc($opt['max']),-1);
                continue;
            }
        }

        return $errors;
    }

    /**
     * Build a nice email from the submitted data and send it
     */
    function _sendemail($data,$to){
        global $ID;
        global $conf;

        $sub = 'Form submitted at '.$ID;
        $txt = "The following data was submitted at page $ID\n\n\n";
        foreach($data as $opt){
            $value = $_POST['bureaucracy'][$opt['idx']];

            switch($opt['cmd']){
                case 'fieldset':
                    $txt .= "\n====== ".hsc($opt['label'])." ======\n\n";
                    break;
                default:
                    if(in_array($opt['cmd'],$this->nofield)) break;
                    $txt .= $opt['label']."\n";
                    $txt .= "\t\t$value\n";
            }
        }

        return mail_send($to, $sub, $txt, $conf['mailfrom']);
    }

    /**
     * Create the form
     */
    function _htmlform($data,$errors){
        global $ID;

        $form = new Doku_Form('bureaucracy__plugin');
        $form->addHidden('id',$ID);

        foreach($data as $opt){
            if(isset($_POST['bureaucracy'][$opt['idx']])){
                $value = $_POST['bureaucracy'][$opt['idx']];
            }else{
                $value = $opt['default'];
            }
            $name  = 'bureaucracy['.$opt['idx'].']';

            if($errors[$opt['idx']]){
                $class = 'bureaucracy_error';
            }else{
                $class = '';
            }

            // we always start with a fieldset!
            if(!$form->_infieldset && $opt['cmd'] != 'fieldset'){
                $form->startFieldset('');
            }

            // handle different field types
            switch($opt['cmd']){
                case 'fieldset':
                    $form->startFieldset($opt['label']);
                    break;
                case 'submit':
                     $form->addElement(form_makeButton('submit','', $opt['label']));
                    break;
                case 'password':
                    $form->addElement(form_makePasswordField($name,$opt['label'],'',$class));
                    break;
                case 'textbox':
                case 'number':
                case 'email':
                    $form->addElement(form_makeTextField($name,$value,$opt['label'],'',$class));
                    break;
                case 'onoff':
                case 'yesno':
                    $chk = ($value) ? 'checked="checked"' : '';
                    $form->addElement('<label class="'.$class.'"><span>'.hsc($opt['label']).'</span>'.
                                      '<input type="checkbox" name="'.$name.'" value="Yes" '.$chk.' /></label>');
                    break;
                case 'select':
                    $vals = explode('|',$opt['args'][0]);
                    $vals = array_map('trim',$vals);
                    $vals = array_filter($vals);
                    array_unshift($vals,' ');
                    $form->addElement(form_makeListboxField($name,$vals,$value,$opt['label'],'',$class));
                    break;
                case 'static':
                    $form->addElement('<p>'.hsc($opt['label']).'</p>');
                    break;
                case 'textarea':
                    $form->addElement('<label class="'.$class.'"><span>'.hsc($opt['label']).'</span>'.
                                      '<textarea name="'.$name.'" class="edit">'.formText($value).'</textarea>');
                    break;
            }
        }

        ob_start();
        $form->printForm();
        $out .= ob_get_contents();
        ob_end_clean();
        return $out;
    }



    /**
     * Parse a line into (quoted) arguments
     *
     * @author William Fletcher <wfletcher@applestone.co.za>
     */
    function _parse_line($line) {
        $args = array();
        $inQuote = false;
        $len = strlen($line);
        for(  $i = 0 ; $i <= $len; $i++ ) {
            if( $line{$i} == '"' ) {
                if($inQuote) {
                    array_push($args, $arg);
                    $inQuote = false;
                    $arg = '';
                    continue;
                } else {
                    $inQuote = true;
                    continue;
                }
            } else if ( $line{$i} == ' ' ) {
                if($inQuote) {
                    $arg .= ' ';
                    continue;
                } else {
                    if ( strlen($arg) < 1 ) continue;
                    array_push($args, $arg);
                    $arg = '';
                    continue;
                }
            }
            $arg .= $line{$i};
        }
        if ( strlen($arg) > 0 ) array_push($args, $arg);
        return $args;
    }


}

//Setup VIM: ex: et ts=4 enc=utf-8 :