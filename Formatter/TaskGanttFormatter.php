<?php

namespace Kanboard\Plugin\Gantt\Formatter;

use Kanboard\Core\Filter\FormatterInterface;
use Kanboard\Formatter\BaseFormatter;

class TaskGanttFormatter extends BaseFormatter implements FormatterInterface
{
    /**
     * Local cache for project columns.
     *
     * @var array
     */
    private $columns = [];
    private $links = [];

    /**
     * Apply formatter.
     *
     * @return array
     */
    public function format()
    {
        $bars = [];

        foreach ($this->query->findAll() as $task) {
            $bars[] = $this->formatTask($task);
        }

        array_multisort(
            array_column($bars, "start"),
            SORT_ASC,
            array_column($bars, "end"),
            SORT_ASC,
            $bars
        );

        return $bars;
    }

    /**
     * Format a single task.
     *
     * @return array
     */
    private function formatTask(array $task)
    {
        if (!isset($this->columns[$task["project_id"]])) {
            $this->columns[$task["project_id"]] = $this->columnModel->getList(
                $task["project_id"]
            );
        }

        $need_date_calculation = ["start" => false, "end" => false];

        $task["__LINES__"] = [];
        $this->_calculateDatesAndTime($task, $need_date_calculation);
        // TODO: use links to calculate dates, e.g. blocked

        $link_settings = $this->linkModel->getAll();
        $related_tasks = $this->taskLinkModel->getAllGroupedByLabel(
            $task["id"]
        );

        //die('<pre>'.print_r($related_tasks,1).'</pre>');

        // Duration ❌ && Start ❌ && (End ❌ || End ✔) && task is blocked by other
        // -> take max due date of others and set start date of current task
        if (
            $need_date_calculation["start"] &&
            isset($related_tasks["is blocked by"])
        ) {
            $task["calculated_start"] = $task["date_start"] = $this->_getMaxValue(
                $related_tasks["is blocked by"],
                "date_due"
            );
            if ($need_date_calculation["end"]) {
                $task["calculated_end"] = $task["date_due"] = time() > $task["calculated_start"] ? $task["calculated_start"] : time();
            }
            // Duration ❌ && End ❌ Start ❌ && task duplicates by other
            // -> take min start date of others and set start date of current task
            // -> take max due date of others and set due date of current task
        } elseif (
            $need_date_calculation["start"] &&
            $need_date_calculation["end"] &&
            isset($related_tasks["duplicates"])
        ) {
            $task["calculated_start"] = $task["date_start"] = $this->_getMinValue(
                $related_tasks["duplicates"],
                "date_started"
            );
            $task["calculated_end"] = $task["date_due"] = $this->_getMaxValue(
                $related_tasks["duplicates"],
                "date_end"
            );
            // Duration ❌ && End ❌ Start ❌ && task is a parent of others
            // -> take min start date of others and set start date of current task
            // -> take max due date of others and set due date of current task
        } elseif (
            $need_date_calculation["start"] &&
            $need_date_calculation["end"] &&
            isset($related_tasks["is a parent of"])
        ) {
            $task["calculated_start"] = $task["date_start"] = $this->_getMinValue(
                $related_tasks["is a parent of"],
                "date_started"
            );
            $task["calculated_end"] = $task["date_due"] = $this->_getMaxValue(
                $related_tasks["is a parent of"],
                "date_end"
            );
        }

        // duplicates & !start & !end
        if (
            !$task["date_started"] &&
            !$task["date_due"] &&
            isset($related_tasks["duplicates"])
        ) {
            $task["calculated_start"] = $task["date_start"] = $this->_getMinValue(
                $this->_calculateRelatedTasks($related_tasks["duplicates"]),
                "date_started"
            );
            $task["calculated_end"] = $task["date_due"] = $this->_getMaxValue(
                $this->_calculateRelatedTasks($related_tasks["duplicates"]),
                "date_end"
            );
            // duplicates & !start & end
        } elseif (
            !$task["date_started"] &&
            $task["date_due"] &&
            isset($related_tasks["duplicates"])
        ) {
            $task["calculated_start"] = $task["date_start"] = $this->_getMinValue(
                $this->_calculateRelatedTasks($related_tasks["duplicates"]),
                "date_started"
            );
            // duplicates & start & !end
        } elseif (
            $task["date_started"] &&
            !$task["date_due"] &&
            isset($related_tasks["duplicates"])
        ) {
            $task["calculated_end"] = $task["date_due"] = $this->_getMaxValue(
                $this->_calculateRelatedTasks($related_tasks["duplicates"]),
                "date_end"
            );
        }
        // todo : is child of

        // Task connections
        $tasklinks = "";
        foreach ($related_tasks as $type => $links) {
            $settings_key = array_search(
                $type,
                array_column($link_settings, "label")
            );
            $type_visible = $link_settings[$settings_key]["gantt_visible"];

            if ($type_visible) {
                foreach ($links as $link) {
                    $tasklinks = $tasklinks . ", " . $link["task_id"];
                }
            }
        }

        $templateTask = [
            "id" => $task["id"],
            "name" => $task["title"],
            "start" => date("Y-m-d", $task["calculated_start"]),
            "end" => date("Y-m-d", $task["calculated_end"]),
            "progress" => $this->taskModel->getProgress(
                $task,
                $this->columns[$task["project_id"]]
            ),
            "dependencies" => $tasklinks,
            "url" => $this->helper->url->to(
                "TaskViewController",
                "show",
                ["task_id" => $task["id"], "project_id" => $task["project_id"]],
                "",
                true
            ),
            "column" => $task["column_name"],
            "swimlane" => $task["swimlane_name"],
            "category" => $task["category_name"],
            "custom_class" => "bar-color-" . $task["color_id"],
            "column_id" => $task["column_id"],
            "__LINES__" => $task["__LINE__"],
        ];

        // we have to create an array because hook::reference allow only 1 argument
        $reference = ["templateTask" => $templateTask, "task" => $task];
        $this->hook->reference("formatter:gantt:format:task", $reference);
        /**
         * Exemple to use hook in plugin :
         *     // Enable hook with custom method
         *     // (don't forget to use the hook template "template:gantt:task:popup:end-table" to add a <tr/> element in popup !)
         *     $this->hook->on('formatter:gantt:format:task', array($this, 'testGantt'));
         *     // Create this method
         *     public function testGantt(array &$data)
         *     {
         *         $data['templateTask']['assignee_name'] = $data['task']['assignee_name'];
         *     }
         */

        return $reference["templateTask"];
    }

    private function _calculateRelatedTasks($tasks)
    {
        $arr = [];

        foreach ($tasks as &$task) {
            $this->_calculateDatesAndTime($task, $arr);
        }

        return $tasks;
    }

    private function _calculateDatesAndTime(&$task, &$need_date_calculation)
    {
        // calculate some days
        // Start ❌ Duration ✔ End ❌
        if (
            !$task["date_started"] &&
            $task["time_estimated"] &&
            !$task["date_due"]
        ) {
            $task["__LINE__"][] = __LINE__;
            $task["calculated_start"] = time();
            $secondsToAdd = $task["time_estimated"] * (60 * 60);
            $task["calculated_end"] = $task["calculated_start"] + $secondsToAdd;
            // Start ✔ Duration ✔ End ❌
        } elseif (
            $task["date_started"] &&
            $task["time_estimated"] &&
            !$task["date_due"]
        ) {
            $task["__LINE__"][] = __LINE__;
            $task["calculated_start"] = $task["date_started"];
            $secondsToAdd = $task["time_estimated"] * (60 * 60);
            $task["calculated_end"] = $task["calculated_start"] + $secondsToAdd;
            // Start ❌ Duration ✔ End  ✔
        } elseif (
            !$task["date_started"] &&
            $task["time_estimated"] &&
            $task["date_due"]
        ) {
            $task["__LINE__"][] = __LINE__;
            $task["calculated_end"] = $task["date_due"];
            $secondsToSub = $task["time_estimated"] * (60 * 60);
            $task["calculated_start"] = $task["calculated_end"] - $secondsToSub;
            // Start ❌ Duration ❌ End ❌
        } elseif (
            !$task["date_started"] &&
            !$task["time_estimated"] &&
            !$task["date_due"]
        ) {
            $task["__LINE__"][] = __LINE__;
            $task["calculated_start"] = $task["date_started"] ?: time();
            $task["calculated_end"] = $task["date_due"] ?: $task["calculated_start"];
            $need_date_calculation = ["start" => true, "end" => true];
            // Start ✔ Duration ✔ End ✔
        } elseif (
            $task["date_started"] &&
            $task["time_estimated"] &&
            $task["date_due"]
        ) {
            $task["__LINE__"][] = __LINE__;
            $task["calculated_start"] = $task["date_started"];
            $secondsToAdd = $task["time_estimated"] * (60 * 60);
            $task["calculated_end"] =
                $task["calculated_start"] + $secondsToAdd > $task["date_due"]
                ? $task["calculated_start"] + $secondsToAdd
                : $task["calculated_start"]["date_started"];
            // Start ✔ Duration ❌ End ❌
        } elseif (
            $task["date_started"] &&
            !$task["time_estimated"] &&
            !$task["date_due"]
        ) {
            $task["__LINE__"][] = __LINE__;
            $task["calculated_start"] = $task["date_started"];
            $task["calculated_end"] = $task["date_started"];
            //$need_date_calculation = true;
            // Start ✔ Duration ❌ End ✔
        } elseif (
            $task["date_started"] &&
            !$task["time_estimated"] &&
            $task["date_due"]
        ) {
            $task["__LINE__"][] = __LINE__;
            $task["calculated_start"] = $task["date_started"];
            $task["calculated_end"] = $task["date_due"];
            // Start ❌ Duration ❌ End  ✔
        } else {
            $task["__LINE__"][] = __LINE__;
            $task["calculated_start"] = $task["date_started"] ?: time();
            $task["calculated_end"] = $task["date_due"] ?: $task["calculated_start"];
        }
    }

    private function _getMinValue($array, $index)
    {
        $min = PHP_INT_MAX;
        foreach ($array as $arr) {
            if (isset($arr[$index]) && $arr[$index]) {
                $min = $min < $arr[$index] ? $min : $arr[$index];
            }
        }

        return $min == PHP_INT_MAX ? 0 : $min;
    }

    private function _getMaxValue($array, $index)
    {
        $max = 0;
        foreach ($array as $arr) {
            if (isset($arr[$index]) && $arr[$index]) {
                $max = $max > $arr[$index] ? $max : $arr[$index];
            }
        }

        return $max;
    }
}
