<?php

/**
 * Class-GameCounter.php
 *
 * @package prdx:gamecounter
 * @link https://cientoseis.es
 */

if (!defined('SMF'))
    die('No direct access...');

final class GameCounter
{
    private const CACHE_VERSION = 2;
    private const ADMIN_GROUP = 1;

    public static function hooks(): void
    {
        add_integration_function('integrate_admin_areas', __CLASS__ . '::adminAreas', false, __FILE__);
        add_integration_function('integrate_modify_modifications', __CLASS__ . '::modifyModifications', false, __FILE__);
        add_integration_function('integrate_bbc_codes', __CLASS__ . '::bbcCodes', false, __FILE__);
        add_integration_function('integrate_display_topic', __CLASS__ . '::displayTopic', false, __FILE__);
        add_integration_function('integrate_buffer', __CLASS__ . '::buffer', false, __FILE__);
        add_integration_function('integrate_after_create_post', __CLASS__ . '::afterCreatePost', false, __FILE__);
        add_integration_function('integrate_modify_post', __CLASS__ . '::modifyPost', false, __FILE__);
        add_integration_function('integrate_remove_message', __CLASS__ . '::removeMessage', false, __FILE__);
        add_integration_function('integrate_remove_topics', __CLASS__ . '::removeTopics', false, __FILE__);
        add_integration_function('integrate_after_approve_posts', __CLASS__ . '::afterApprovePosts', false, __FILE__);
    }

    private static function localLoadLanguage(string $template = 'GameCounter/GameCounter'): void
    {
        $is_package_area = isset($_GET['action'], $_GET['area']) &&
            $_GET['action'] === 'admin' &&
            $_GET['area'] === 'packages';

        loadLanguage($template, '', !$is_package_area);
    }

    public static function adminAreas(array &$admin_areas): void
    {
        global $txt;

        self::localLoadLanguage();

        if (isset($admin_areas['config']['areas']['modsettings']['subsections']))
            $admin_areas['config']['areas']['modsettings']['subsections']['gamecounter'] = [
                $txt['gamecounter_admin_title'],
            ];
    }

    public static function modifyModifications(array &$subActions): void
    {
        global $context, $txt;

        self::localLoadLanguage();

        $subActions['gamecounter'] = __CLASS__ . '::adminSettings';

        if (!empty($context['admin_menu_name']) && isset($_REQUEST['sa']) && $_REQUEST['sa'] === 'gamecounter')
        {
            $context[$context['admin_menu_name']]['current_subsection'] = 'gamecounter';
            $context[$context['admin_menu_name']]['tab_data']['description'] = $txt['gamecounter_admin_desc'];
        }
    }

    public static function adminSettings(bool $return_config = false)
    {
        global $context, $scripturl, $txt;

        self::localLoadLanguage();

        $config_vars = self::getAdminConfigVars();

        if ($return_config)
            return $config_vars;

        $context['post_url'] = $scripturl . '?action=admin;area=modsettings;save;sa=gamecounter';
        $context['settings_title'] = $txt['gamecounter_admin_title'];

        if (isset($_GET['save']))
        {
            self::normalizeAdminPost();
            saveDBSettings($config_vars);
            self::invalidateTopics(self::parseTopicList($_POST['gamecounter_topics'] ?? ''));
            $_SESSION['adm-save'] = true;
            redirectexit('action=admin;area=modsettings;sa=gamecounter');
        }

        prepareDBSettingContext($config_vars);
    }

    private static function getAdminConfigVars(): array
    {
        return [
            ['title', 'gamecounter_admin_title'],
            ['check', 'gamecounter_enabled'],
            ['text', 'gamecounter_title', 40],
            ['large_text', 'gamecounter_topics', 5],
            ['large_text', 'gamecounter_blocked_users', 5],
            ['int', 'gamecounter_max_points', 6, 'min' => 1, 'max' => 99],
            ['int', 'gamecounter_cache_ttl', 6, 'min' => 60, 'max' => 86400],
            ['check', 'gamecounter_show_empty'],
        ];
    }

    private static function normalizeAdminPost(): void
    {
        $_POST['gamecounter_topics'] = self::normalizeMultilineList($_POST['gamecounter_topics'] ?? '', true);
        $_POST['gamecounter_blocked_users'] = self::normalizeMultilineList($_POST['gamecounter_blocked_users'] ?? '', false);
        $_POST['gamecounter_title'] = trim((string) ($_POST['gamecounter_title'] ?? ''));

        if ($_POST['gamecounter_title'] === '')
            $_POST['gamecounter_title'] = 'Game Counter';

        if (empty($_POST['gamecounter_max_points']))
            $_POST['gamecounter_max_points'] = 5;

        if (empty($_POST['gamecounter_cache_ttl']))
            $_POST['gamecounter_cache_ttl'] = 3600;
    }

    private static function normalizeMultilineList(string $value, bool $integers_only): string
    {
        $items = preg_split('~[\r\n,;]+~', $value);
        $clean = [];

        foreach ($items as $item)
        {
            $item = trim($item);
            if ($item === '')
                continue;

            if ($integers_only)
            {
                $id = (int) $item;
                if ($id > 0)
                    $clean[$id] = (string) $id;
            }
            else
            {
                $clean[self::normalizeKey($item)] = $item;
            }
        }

        return implode("\n", $clean);
    }

    public static function bbcCodes(array &$codes): void
    {
        global $txt;

        self::localLoadLanguage();

        $codes[] = [
            'tag' => 'gamepoint',
            'type' => 'unparsed_equals',
            'before' => '',
            'after' => '',
            'validate' => function (&$tag, &$data) {
                $point = GameCounter::parseGamepointSpec($data);
                $tag['before'] = $point === null ? '' : GameCounter::formatPointInline($point['player'], $point['points']);
            },
        ];

        foreach (['malqueda', 'noshow'] as $tag_name)
        {
            $codes[] = [
                'tag' => $tag_name,
                'type' => 'unparsed_equals',
                'before' => '',
                'after' => '',
                'validate' => function (&$tag, &$data) {
                    $malqueda = GameCounter::parseMalquedaSpec($data);
                    $tag['before'] = $malqueda === null ? '' : GameCounter::formatMalquedaInline($malqueda['player'], $malqueda['count']);
                },
            ];
        }

        $codes[] = [
            'tag' => 'initgamecounter',
            'type' => 'unparsed_content',
            'block_level' => true,
            'content' => '<div class="gamecounter-init-bbc">$1</div>',
            'validate' => function (&$tag, &$data) use ($txt) {
                $data = preg_replace('~<br\s*/?>~i', "\n", $data);
                $data = nl2br(GameCounter::escape(strip_tags($data)), false);
                $tag['content'] = '<div class="gamecounter-init-bbc"><strong>' . $txt['gamecounter_init_label'] . '</strong><div class="gamecounter-init-lines">$1</div></div>';
            },
        ];

        $codes[] = [
            'tag' => 'initmalquedas',
            'type' => 'unparsed_content',
            'block_level' => true,
            'content' => '<div class="gamecounter-init-bbc">$1</div>',
            'validate' => function (&$tag, &$data) use ($txt) {
                $data = preg_replace('~<br\s*/?>~i', "\n", $data);
                $data = nl2br(GameCounter::escape(strip_tags($data)), false);
                $tag['content'] = '<div class="gamecounter-init-bbc"><strong>' . $txt['gamecounter_init_malquedas_label'] . '</strong><div class="gamecounter-init-lines">$1</div></div>';
            },
        ];
    }

    public static function displayTopic(array &$topic_selects, array &$topic_tables, array &$topic_parameters): void
    {
        global $context, $modSettings, $topic;

        if (empty($modSettings['gamecounter_enabled']) || empty($topic) || !self::isActiveTopic((int) $topic))
            return;

        self::localLoadLanguage();
        loadCSSFile('GameCounter.css', ['minimize' => true], 'gamecounter');

        $board = self::getTopicCounter((int) $topic);
        $context['gamecounter_board_html'] = self::renderBoard($board);
    }

    public static function buffer(string $buffer): string
    {
        global $context;

        if (empty($context['gamecounter_board_html']) || strpos($buffer, 'id="forumposts"') === false)
            return $buffer;

        return preg_replace_callback(
            '~(\s*<div id="forumposts">)~',
            function ($matches) use ($context) {
                return "\n" . $context['gamecounter_board_html'] . $matches[1];
            },
            $buffer,
            1
        );
    }

    public static function afterCreatePost($msgOptions, $topicOptions, $posterOptions, $message_columns, $message_parameters): void
    {
        $topic_id = (int) ($topicOptions['id'] ?? 0);
        if ($topic_id > 0)
            self::invalidateTopic($topic_id);
    }

    public static function modifyPost(&$messages_columns, &$update_parameters, &$msgOptions, &$topicOptions, &$posterOptions, &$messageInts): void
    {
        $topic_id = (int) ($topicOptions['id'] ?? 0);
        if ($topic_id > 0)
            self::invalidateTopic($topic_id);
    }

    public static function removeMessage($message, $row, $recycle): void
    {
        $topic_id = (int) ($row['id_topic'] ?? 0);
        if ($topic_id > 0)
            self::invalidateTopic($topic_id);
    }

    public static function removeTopics($topics): void
    {
        self::invalidateTopics(array_map('intval', (array) $topics));
    }

    public static function afterApprovePosts($approve, $msgs, $topic_changes, $member_post_changes): void
    {
        global $smcFunc;

        $topics = array_map('intval', array_keys((array) $topic_changes));

        if (empty($topics) && !empty($msgs))
        {
            $request = $smcFunc['db_query']('', '
                SELECT DISTINCT id_topic
                FROM {db_prefix}messages
                WHERE id_msg IN ({array_int:messages})',
                [
                    'messages' => array_map('intval', (array) $msgs),
                ]
            );

            while ($row = $smcFunc['db_fetch_assoc']($request))
                $topics[] = (int) $row['id_topic'];

            $smcFunc['db_free_result']($request);
        }

        self::invalidateTopics($topics);
    }

    private static function getTopicCounter(int $topic_id): array
    {
        $ttl = self::cacheTtl();
        $cache_key = self::cacheKey($topic_id);

        if ($ttl > 0)
        {
            $cached = cache_get_data($cache_key, $ttl);
            if (is_array($cached) && ($cached['version'] ?? 0) === self::CACHE_VERSION)
                return $cached;
        }

        $counter = self::rebuildTopicCounter($topic_id);

        if ($ttl > 0)
            cache_put_data($cache_key, $counter, $ttl);

        return $counter;
    }

    private static function rebuildTopicCounter(int $topic_id): array
    {
        global $smcFunc;

        $blocked = self::blockedUsers();
        $players = [];
        $events = 0;
        $malqueda_events = 0;
        $baseline_msg = 0;

        $request = $smcFunc['db_query']('', '
            SELECT
                m.id_msg, m.id_member, m.poster_name, m.body,
                COALESCE(mem.real_name, m.poster_name) AS author_name,
                COALESCE(mem.id_group, 0) AS id_group,
                COALESCE(mem.additional_groups, {string:empty}) AS additional_groups
            FROM {db_prefix}messages AS m
                LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
            WHERE m.id_topic = {int:topic}
                AND m.approved = {int:approved}
            ORDER BY m.id_msg ASC',
            [
                'topic' => $topic_id,
                'approved' => 1,
                'empty' => '',
            ]
        );

        while ($row = $smcFunc['db_fetch_assoc']($request))
        {
            $body = self::stripIgnoredBlocks($row['body']);

            if (self::isAdminAuthor($row))
            {
                $initial = self::extractInitialScores($body);
                if ($initial !== null)
                {
                    $players = $initial;
                    $events = 0;
                    $malqueda_events = 0;
                    $baseline_msg = (int) $row['id_msg'];

                    $initial_malquedas = self::extractInitialMalquedas($body);
                    if ($initial_malquedas !== null)
                    {
                        self::clearMalquedas($players);
                        foreach ($initial_malquedas as $malqueda)
                            self::addMalquedas($players, $malqueda['player'], $malqueda['count']);
                    }

                    continue;
                }

                $initial_malquedas = self::extractInitialMalquedas($body);
                if ($initial_malquedas !== null)
                {
                    self::clearMalquedas($players);
                    foreach ($initial_malquedas as $malqueda)
                        self::addMalquedas($players, $malqueda['player'], $malqueda['count']);

                    $malqueda_events = 0;
                    continue;
                }
            }

            if (self::isBlockedAuthor($row, $blocked))
                continue;

            foreach (self::extractGamepoints($body) as $point)
            {
                self::addPoints($players, $point['player'], $point['points']);
                $events += $point['points'];
            }

            foreach (self::extractMalquedas($body) as $malqueda)
            {
                self::addMalquedas($players, $malqueda['player'], $malqueda['count']);
                $malqueda_events += $malqueda['count'];
            }
        }

        $smcFunc['db_free_result']($request);

        uasort($players, function ($a, $b) {
            if ($a['points'] === $b['points'])
                return strcasecmp($a['name'], $b['name']);

            return $b['points'] <=> $a['points'];
        });

        return [
            'version' => self::CACHE_VERSION,
            'topic' => $topic_id,
            'players' => array_values($players),
            'events' => $events,
            'malquedas' => $malqueda_events,
            'baseline_msg' => $baseline_msg,
            'updated' => time(),
        ];
    }

    private static function stripIgnoredBlocks(string $body): string
    {
        $patterns = [
            '~\[quote(?:[^\]]*)\].*?\[/quote\]~is',
            '~\[code(?:[^\]]*)\].*?\[/code\]~is',
            '~\[nobbc\].*?\[/nobbc\]~is',
        ];

        $previous = null;
        while ($previous !== $body)
        {
            $previous = $body;
            $body = preg_replace($patterns, '', $body);
        }

        return $body;
    }

    private static function extractInitialScores(string $body): ?array
    {
        if (!preg_match_all('~\[initgamecounter\](.*?)\[/initgamecounter\]~is', $body, $matches))
            return null;

        $block = end($matches[1]);
        $players = [];
        $lines = preg_split('~\R+~', str_replace(['<br>', '<br />'], "\n", $block));

        foreach ($lines as $line)
        {
            $line = trim(strip_tags($line));
            if ($line === '')
                continue;

            if (!preg_match('~^\s*(\d+)\s*(?:puntos?|points?)?\s*[:=-]\s*(.+)$~i', $line, $match))
                continue;

            $points = (int) $match[1];
            $names = preg_split('~\s*,\s*~', $match[2]);

            foreach ($names as $name)
            {
                $name = self::normalizePlayerName($name);
                if ($name !== '')
                    self::addPoints($players, $name, $points);
            }
        }

        return $players;
    }

    private static function extractInitialMalquedas(string $body): ?array
    {
        if (!preg_match_all('~\[initmalquedas\](.*?)\[/initmalquedas\]~is', $body, $matches))
            return null;

        $block = end($matches[1]);
        $malquedas = [];
        $lines = preg_split('~\R+~', str_replace(['<br>', '<br />'], "\n", $block));

        foreach ($lines as $line)
        {
            $line = trim(strip_tags($line));
            if ($line === '')
                continue;

            if (!preg_match('~^\s*(\d+)\s*(?:malquedas?|no-?shows?)?\s*[:=-]\s*(.+)$~i', $line, $match))
                continue;

            $count = (int) $match[1];
            $names = preg_split('~\s*,\s*~', $match[2]);

            foreach ($names as $name)
            {
                $name = self::normalizePlayerName($name);
                if ($name !== '')
                    $malquedas[] = ['player' => $name, 'count' => $count];
            }
        }

        return $malquedas;
    }

    private static function extractGamepoints(string $body): array
    {
        if (!preg_match_all('~\[gamepoint=([^\]]+)\]~i', $body, $matches))
            return [];

        $points = [];
        foreach ($matches[1] as $spec)
        {
            $point = self::parseGamepointSpec($spec);
            if ($point !== null)
                $points[] = $point;
        }

        return $points;
    }

    private static function extractMalquedas(string $body): array
    {
        if (!preg_match_all('~\[(?:malqueda|noshow)=([^\]]+)\]~i', $body, $matches))
            return [];

        $malquedas = [];
        foreach ($matches[1] as $spec)
        {
            $malqueda = self::parseMalquedaSpec($spec);
            if ($malqueda !== null)
                $malquedas[] = $malqueda;
        }

        return $malquedas;
    }

    public static function parseGamepointSpec(string $spec): ?array
    {
        $spec = trim(self::decodeEntities($spec));
        if ($spec === '')
            return null;

        $points = 1;
        $player = $spec;

        if (preg_match('~^([\'"])(.*?)\1\s*(.*)$~s', $spec, $match))
        {
            $player = $match[2];
            $rest = trim($match[3]);
            $parsed = self::parsePointSuffix($rest);
            if ($parsed !== null)
                $points = $parsed;
        }
        else
        {
            $parsed = self::parsePointSuffix($spec);
            if ($parsed !== null)
            {
                $points = $parsed;
                $player = trim(preg_replace('~\s+(?:(?:points?|puntos?)\s*=\s*\d+|\+\d+)\s*$~i', '', $spec));
            }
        }

        $player = self::normalizePlayerName($player);
        if ($player === '')
            return null;

        $points = max(1, min(self::maxPoints(), $points));

        return [
            'player' => $player,
            'points' => $points,
        ];
    }

    public static function parseMalquedaSpec(string $spec): ?array
    {
        $spec = trim(self::decodeEntities($spec));
        if ($spec === '')
            return null;

        $count = 1;
        $player = $spec;

        if (preg_match('~^([\'"])(.*?)\1\s*(.*)$~s', $spec, $match))
        {
            $player = $match[2];
            $rest = trim($match[3]);
            $parsed = self::parseMalquedaSuffix($rest);
            if ($parsed !== null)
                $count = $parsed;
        }
        else
        {
            $parsed = self::parseMalquedaSuffix($spec);
            if ($parsed !== null)
            {
                $count = $parsed;
                $player = trim(preg_replace('~\s+(?:(?:count|malquedas?|no-?shows?)\s*=\s*\d+|\+\d+)\s*$~i', '', $spec));
            }
        }

        $player = self::normalizePlayerName($player);
        if ($player === '')
            return null;

        $count = max(1, min(99, $count));

        return [
            'player' => $player,
            'count' => $count,
        ];
    }

    private static function parsePointSuffix(string $value): ?int
    {
        $value = trim($value);
        if ($value === '')
            return null;

        if (preg_match('~(?:^|\s)(?:points?|puntos?)\s*=\s*(\d+)\s*$~i', $value, $match))
            return (int) $match[1];

        if (preg_match('~(?:^|\s)\+(\d+)\s*$~', $value, $match))
            return (int) $match[1];

        return null;
    }

    private static function parseMalquedaSuffix(string $value): ?int
    {
        $value = trim($value);
        if ($value === '')
            return null;

        if (preg_match('~(?:^|\s)(?:count|malquedas?|no-?shows?)\s*=\s*(\d+)\s*$~i', $value, $match))
            return (int) $match[1];

        if (preg_match('~(?:^|\s)\+(\d+)\s*$~', $value, $match))
            return (int) $match[1];

        return null;
    }

    private static function addPoints(array &$players, string $player, int $points): void
    {
        $key = self::normalizeKey($player);
        if ($key === '')
            return;

        if (!isset($players[$key]))
            $players[$key] = ['name' => $player, 'points' => 0, 'malquedas' => 0];

        $players[$key]['points'] += $points;
    }

    private static function addMalquedas(array &$players, string $player, int $count): void
    {
        $key = self::normalizeKey($player);
        if ($key === '')
            return;

        if (!isset($players[$key]))
            $players[$key] = ['name' => $player, 'points' => 0, 'malquedas' => 0];

        if (!isset($players[$key]['malquedas']))
            $players[$key]['malquedas'] = 0;

        $players[$key]['malquedas'] += $count;
    }

    private static function clearMalquedas(array &$players): void
    {
        foreach ($players as &$player)
            $player['malquedas'] = 0;
        unset($player);
    }

    private static function renderBoard(array $board): string
    {
        global $modSettings, $txt;

        self::localLoadLanguage();

        $players = $board['players'] ?? [];
        if (empty($players) && empty($modSettings['gamecounter_show_empty']))
            return '';

        $groups = [];
        foreach ($players as $player)
            $groups[(int) $player['points']][] = $player;

        krsort($groups, SORT_NUMERIC);

        $title = trim((string) ($modSettings['gamecounter_title'] ?? ''));
        if ($title === '')
            $title = $txt['gamecounter_default_title'];

        $html = '
        <section class="gamecounter-board information">
            <div class="gamecounter-heading">
                <h3>' . self::escape($title) . '</h3>
                <span class="gamecounter-meta">' . sprintf($txt['gamecounter_meta'], count($players), (int) ($board['events'] ?? 0), (int) ($board['malquedas'] ?? 0)) . '</span>
            </div>';

        if (empty($groups))
        {
            $html .= '
            <p class="gamecounter-empty">' . $txt['gamecounter_empty'] . '</p>
        </section>';
            return $html;
        }

        $html .= '
            <table class="gamecounter-table">
                <thead>
                    <tr>
                        <th scope="col">' . $txt['gamecounter_points'] . '</th>
                        <th scope="col">' . $txt['gamecounter_players'] . '</th>
                    </tr>
                </thead>
                <tbody>';

        $rank = 0;
        foreach ($groups as $points => $entries)
        {
            $rank++;
            usort($entries, function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });

            $names = [];
            foreach ($entries as $entry)
                $names[] = self::formatBoardPlayerName($entry);

            $html .= '
                    <tr class="' . ($rank <= 3 ? 'gamecounter-top gamecounter-top-' . $rank : '') . '">
                        <td class="gamecounter-score">' . (int) $points . '</td>
                        <td class="gamecounter-names">' . implode(', ', $names) . '</td>
                    </tr>';
        }

        $html .= '
                </tbody>
            </table>
        </section>';

        return $html;
    }

    private static function formatBoardPlayerName(array $player): string
    {
        $name = self::escape((string) ($player['name'] ?? ''));
        $malquedas = (int) ($player['malquedas'] ?? 0);

        if ($malquedas <= 0)
            return $name;

        return $name . ' <span class="gamecounter-malqueda-note">(' . sprintf(self::malquedaCountText($malquedas), $malquedas) . ')</span>';
    }

    public static function formatPointInline(string $player, int $points): string
    {
        global $txt;

        self::localLoadLanguage();

        return '<span class="gamecounter-point">' . sprintf($txt['gamecounter_point_inline'], self::escape($player), (int) $points) . '</span> ';
    }

    public static function formatMalquedaInline(string $player, int $count): string
    {
        global $txt;

        self::localLoadLanguage();

        return '<span class="gamecounter-malqueda">' . sprintf($txt['gamecounter_malqueda_inline'], self::escape($player), (int) $count) . '</span> ';
    }

    private static function malquedaCountText(int $count): string
    {
        global $txt;

        self::localLoadLanguage();

        return $count === 1 ? $txt['gamecounter_malqueda_count_singular'] : $txt['gamecounter_malqueda_count_plural'];
    }

    private static function isActiveTopic(int $topic_id): bool
    {
        return in_array($topic_id, self::activeTopics(), true);
    }

    private static function activeTopics(): array
    {
        global $modSettings;

        return self::parseTopicList($modSettings['gamecounter_topics'] ?? '');
    }

    private static function parseTopicList(string $value): array
    {
        preg_match_all('~\d+~', $value, $matches);

        return array_values(array_unique(array_map('intval', $matches[0] ?? [])));
    }

    private static function blockedUsers(): array
    {
        global $modSettings;

        $blocked = [
            'ids' => [],
            'names' => [],
        ];

        $items = preg_split('~[\r\n,;]+~', (string) ($modSettings['gamecounter_blocked_users'] ?? ''));
        foreach ($items as $item)
        {
            $item = trim($item);
            if ($item === '')
                continue;

            if (ctype_digit($item))
                $blocked['ids'][(int) $item] = true;
            else
                $blocked['names'][self::normalizeKey($item)] = true;
        }

        return $blocked;
    }

    private static function isBlockedAuthor(array $row, array $blocked): bool
    {
        $id = (int) ($row['id_member'] ?? 0);
        if ($id > 0 && isset($blocked['ids'][$id]))
            return true;

        $name = self::normalizeKey((string) ($row['author_name'] ?? $row['poster_name'] ?? ''));

        return $name !== '' && isset($blocked['names'][$name]);
    }

    private static function isAdminAuthor(array $row): bool
    {
        if ((int) ($row['id_group'] ?? 0) === self::ADMIN_GROUP)
            return true;

        $additional = array_filter(array_map('intval', explode(',', (string) ($row['additional_groups'] ?? ''))));

        return in_array(self::ADMIN_GROUP, $additional, true);
    }

    private static function normalizePlayerName(string $name): string
    {
        $name = trim(self::decodeEntities(strip_tags($name)));
        $name = preg_replace('~\s+~', ' ', $name);

        global $smcFunc;

        if (isset($smcFunc['substr']))
            return $smcFunc['substr']($name, 0, 80);

        return substr($name, 0, 80);
    }

    private static function normalizeKey(string $value): string
    {
        global $smcFunc;

        $value = trim(self::decodeEntities(strip_tags($value)));
        $value = preg_replace('~\s+~', ' ', $value);

        if (isset($smcFunc['strtolower']))
            return $smcFunc['strtolower']($value);

        return strtolower($value);
    }

    private static function decodeEntities(string $value): string
    {
        if (function_exists('un_htmlspecialchars'))
            return un_htmlspecialchars($value);

        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }

    private static function escape(string $value): string
    {
        global $smcFunc;

        if (isset($smcFunc['htmlspecialchars']))
            return $smcFunc['htmlspecialchars']($value, ENT_QUOTES);

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function maxPoints(): int
    {
        global $modSettings;

        return max(1, min(99, (int) ($modSettings['gamecounter_max_points'] ?? 5)));
    }

    private static function cacheTtl(): int
    {
        global $modSettings;

        return max(0, (int) ($modSettings['gamecounter_cache_ttl'] ?? 3600));
    }

    private static function cacheKey(int $topic_id): string
    {
        return 'gamecounter_topic_' . $topic_id;
    }

    private static function invalidateTopic(int $topic_id): void
    {
        cache_put_data(self::cacheKey($topic_id), null);
    }

    private static function invalidateTopics(array $topics): void
    {
        foreach (array_unique(array_filter(array_map('intval', $topics))) as $topic_id)
            self::invalidateTopic($topic_id);
    }
}
