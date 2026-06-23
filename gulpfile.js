/* GULP itself */
'use strict';

import util              from 'util';
import fs                from 'fs';
import ini               from 'ini';

import gulp              from 'gulp';
import requirejsOptimize from 'gulp-requirejs-optimize';
import filter            from 'gulp-filter';
import gulpif            from 'gulp-if';
import {ESLint}          from 'eslint';
import sourcemaps        from 'gulp-sourcemaps';
import zlib              from 'zlib';
import gzip               from 'gulp-gzip';
import brotli             from 'gulp-brotli';
import terser             from 'gulp-terser';
import sassCompiler       from 'sass';
import gulpSass           from 'gulp-sass';
import autoprefixer       from 'gulp-autoprefixer';
import cleanCSS           from 'gulp-clean-css';
import sharp              from 'sharp';
import through2           from 'through2';
import rename             from 'gulp-rename';
import bytediff           from 'gulp-bytediff';
import debug              from 'gulp-debug';
import notifier           from 'node-notifier';

// -- Helper & NPM modules ----------------------------------------------------
import {flatten}     from 'flat';
import padEnd         from 'lodash.padend';
import merge          from 'lodash.merge';
import minimist       from 'minimist';
import fileExtension  from 'file-extension';
import log            from 'fancy-log';
import colors         from 'ansi-colors';
import Table          from 'terminal-table';
import prettyBytes    from 'pretty-bytes';
import del            from 'promised-del';

let sass = gulpSass(sassCompiler);

// == Settings ========================================================================================================

// build/src directories
let PATH = {
    ASSETS: {
        DEST:           './public'
    },
    JS: {
        SRC:            'js/**/*.js',
        SRC_LIBS:       './js/lib/**/*',
        DEST:           'public/js',
        DEST_BUILD:     './public/js/vX.X.X'
    },
    CSS: {
        SRC:            'sass/**/*.scss',
    },
    IMG: {
        SRC:            'img/**/*.{jpg,png,svg}',
        SRC_HEADER:     'img/header/*.{jpg,png}',
        SRC_GALLERY:    'img/gallery/**/*.{jpg,png}',
        SRC_SVG:        'img/svg/*.svg'
    }
};

// Pathfinder config files
let pathfinderConfigFileApp = './app/pathfinder.ini';
let pathfinderConfigFileConf = './conf/pathfinder.ini';

// CLI box size in characters
let cliBoxLength = 80;

// cache for already combined JS files
let combinedJsFiles = [];

// cache for tracked JS files
let trackedFiles = {};

// columns for tracked JS files (used for mapping)
let trackTable = {
    cols: [
        'file',
        'src',
        'src_percent',
        'uglify',
        'gzipFile',
        'gzip_percent',
        'gzip',
        'brotliFile',
        'brotli_percent',
        'brotli',
        'all_percent',
        'mapFile',
        'map'
    ]
};

// Terser options
// https://www.npmjs.com/package/terser
let uglifyJsOptions = {
    compress: {warnings: true},
    toplevel: false,
    ecma: 8,
    nameCache: {},          // cache mangled variable and property names across multiple invocations of minify()
    keep_classnames: true   // pass true to prevent discarding or mangling of class names.
};

// Sourcemaps options
// https://www.npmjs.com/package/gulp-sourcemaps

// -- Error output ------------------------------------------------------------

/**
 * print error box output
 * @param title
 * @param example
 */
let printError = (title, example) => {
    log('');
    log(colors.danger.divider('error'));
    log(colors.iconDanger(title));
    if(example){
        log(`     ${colors.gray(example)}`);
    }
    log(colors.danger.divider(' '));
    log('');
};

// == Settings ========================================================================================================

// parse pathfinder.ini config file for relevant data
let tagVersion;
try{
    let pathfinderAppIni = ini.parse(fs.readFileSync(pathfinderConfigFileApp, 'utf-8'));
    let pathfinderConfIni = fs.existsSync(pathfinderConfigFileConf) ?
        ini.parse(fs.readFileSync(pathfinderConfigFileConf, 'utf-8')) : {};
    let pathfinderIni = merge(pathfinderAppIni, pathfinderConfIni);

    try{
        tagVersion = pathfinderIni.PATHFINDER.VERSION;
    }catch(err){
        printError(
            err.message,
            'Missing "PATHFINDER.VERSION" in "' + pathfinderConfigFileApp + '"');
        process.exit(1);
    }
}catch(err){
    printError(
        err.message,
        'Check read permissions for "' + pathfinderConfigFileApp + '"');
    process.exit(1);
}

// parse CLI parameters
let options = minimist(process.argv.slice(2));

// custom task configuration (user CLI options if provided) (overwrites default)
let CONF = {
    TASK: options.hasOwnProperty('_') ? options._[0] : undefined,
    TAG: options.tag ? options.tag : tagVersion ? tagVersion : undefined,
    JS: {
        UGLIFY: options.hasOwnProperty('jsUglify') ? options.jsUglify === 'true': undefined,
        SOURCEMAPS: options.hasOwnProperty('jsSourcemaps') ? options.jsSourcemaps === 'true': undefined,
        GZIP: options.hasOwnProperty('jsGzip') ? options.jsGzip === 'true': undefined,
        BROTLI: options.hasOwnProperty('jsBrotli') ? options.jsBrotli === 'true': undefined
    },
    CSS: {
        SOURCEMAPS: options.hasOwnProperty('cssSourcemaps') ? options.cssSourcemaps === 'true': undefined,
        GZIP: options.hasOwnProperty('cssGzip') ? options.cssGzip === 'true': undefined,
        BROTLI: options.hasOwnProperty('cssBrotli') ? options.cssBrotli === 'true': undefined
    },
    IMG: {
        ACTIVE: options.hasOwnProperty('imgActive') ? options.imgActive === 'true': undefined,
    },
    DEBUG: false
};

// -- Plugin options ----------------------------------------------------------
let sassOptions = {
    errorLogToConsole: true,
    outputStyle: 'compressed', // nested, expanded, compact, compressed
    loadPaths: [process.cwd()] // gulp-sass 6 (new dart-sass JS API) no longer adds CWD implicitly; needed for root-relative @import "sass/…"
};

let autoprefixerOptions = {
    // browsers: ['last 2 versions'], read from package.json key: "browserslist"
    cascade: false
};

let cleanCssOptions = {
    compatibility: '*',
    level: 2
};

let gZipOptions = {
    append: false,                      // disables default append ext .gz
    extension: 'gz',                    // use "custom" ext: .gz
    threshold: '1kb',                   // min size required to compress a file
    deleteMode: PATH.JS.DEST_BUILD,     // replace *.gz files if size < 'threshold'
    gzipOptions: {
        level: 9                        // zlib.Gzip compression level [0-9]
    },
    skipGrowingFiles: true              // use orig. files in case of *.gz size > orig. size
};

let brotliOptions = {
    extension: 'br',                    // use "custom" ext: .br
    skipLarger: true,                   // use orig. files in case of *.br size > orig. size
    params: {
        [zlib.constants.BROTLI_PARAM_MODE]: zlib.constants.BROTLI_MODE_TEXT,        // compression mode for UTF-8 formatted text
        [zlib.constants.BROTLI_PARAM_QUALITY]: zlib.constants.BROTLI_MAX_QUALITY    // quality [1 worst - 11 best]
    }
};

let compressionExt = [gZipOptions.extension, brotliOptions.extension];

let webpOptions = {quality: 80};

let imgResizeOptions = {
    crop : true,
    upscale : false
    // metadata/profile is stripped by default (sharp doesn't preserve it unless .withMetadata() is called)
};

colors.theme({
    primary: colors.cyan,
    success: colors.green,
    info: colors.blue,
    warning: colors.yellow,
    danger: colors.red,

    disabled:       (...args) => colors.dim.gray(util.format(...args)),
    comment:        (...args) => colors.disabled.italic(util.format(...args)),

    iconSuccess:    text => ` ${colors.success(`✔`)} ${colors.success(text)}`,
    iconDanger:     text => ` ${colors.danger(`✘`)} ${colors.danger(text)}`,

    divider:        text => colors.disabled(`${(text || '').trim().length ? `═ ${(text || '').trim().toUpperCase()} ═` : ''}`.padEnd(cliBoxLength, '═'))
});

/**
 * example output formatting
 */
/*
log(colors.primary('primary'));
log(colors.success('success'));
log(colors.info('info'));
log(colors.warning('warning'));
log(colors.danger('danger'));

log(colors.disabled('disabled'));
log(colors.comment('comment'));

log(colors.iconSuccess('success'));
log(colors.iconDanger('danger'));

log(colors.bold('bold'));
log(colors.italic('italic'));
log(colors.underline('underline'));
log('');
log(colors.magenta('magenta'));
log(colors.magentaBright('magentaBright'));
log(colors.cyan('cyan'));
log(colors.cyanBright('cyanBright'));
log(colors.blue('blue'));
log(colors.blueBright('blueBright'));
log(colors.yellow('yellow'));
log(colors.yellowBright('yellowBright'));
log('');
log('');
*/

// == Helper methods ==================================================================================================

/**
 * track a file by size and filename, provide a mapping (see trackTable)
 * @param data
 * @param mapping
 */
let trackFile = (data, mapping) => {
    let fileNameParts = data.fileName.split('.');
    let fileExt = fileNameParts.pop();
    let srcFileName = compressionExt.concat(['map']).includes(fileExt) ? fileNameParts.join('.') : data.fileName;
    let fileData = trackedFiles[srcFileName] || [];

    // change mapping for *.map files
    switch(fileExt){
        case 'js':
            mapping.all_percent = 'percent';
            break;
        case 'map':
            mapping = {mapFile: 'fileName', map: 'endSize'};
            break;
        case gZipOptions.extension:
            data.all_percent = data.endSize / fileData[0];
            mapping = {gzipFile: 'fileName', gzip_percent: 'percent', gzip: 'endSize', all_percent: 'all_percent'};
            break;
        case brotliOptions.extension:
            data.all_percent = data.endSize / fileData[0];
            mapping = {brotliFile: 'fileName', brotli_percent: 'percent', brotli: 'endSize', all_percent: 'all_percent'};
            break;
    }

    for(let col in mapping){
        for(let i = 0; i < trackTable.cols.length; i++){
            if(trackTable.cols[i] === col){
                fileData[i - 1] = data[mapping[col]];
                break;
            }
        }
    }

    trackedFiles[srcFileName] = fileData;
};

/**
 * recursive "merge" two config objects
 * @param confUser
 * @param confDefault
 * @returns {*}
 */
let mergeConf = (confUser, confDefault) => {
    for (let confKey in confUser) {
        if (confUser.hasOwnProperty(confKey)){
            if(confDefault.hasOwnProperty(confKey)){
                if(
                    typeof confUser[confKey] === 'object' &&
                    typeof confDefault[confKey] === 'object'
                ){
                    confUser[confKey] = mergeConf(confUser[confKey], confDefault[confKey]);
                }else if(typeof confUser[confKey] === 'undefined'){
                    confUser[confKey] = confDefault[confKey];
                }
            }
        }
    }

    return confUser;
};

// == CLI output ======================================================================================================

/**
 * print help information for all Gulp tasks
 */
let printHelp = () => {
    log(`
            ${colors.divider('help')}
                ${colors.bold('docs:')}                 ${colors.info('https://github.com/exodus4d/pathfinder/wiki/GulpJs')}
                ${colors.bold('command:')}              ${colors.magenta('$ npm run gulp')} ${colors.primary.italic('[task]')} ${colors.yellow.italic('-- [--options] …')}
                ${colors.bold('tasks:')}
                    ${colors.primary('help')}              This view
                    ${colors.primary('default')}           Development environment. Working with row src files and file watcher, default:
                    ${colors.primary('')}                      ${colors.gray('--jsUglify=false --jsSourcemaps=false --cssSourcemaps=false --jsGzip=false --cssGzip=false --jsBrotli=false --cssBrotli=false --imgActive=true')}
                    ${colors.primary('production')}        Production build. Concat and uglify static resources, default:
                    ${colors.primary('')}                      ${colors.gray('--jsUglify=true --jsSourcemaps=true --cssSourcemaps=true --jsGzip=true --cssGzip=true --jsBrotli=true --cssBrotli=true --imgActive=true')}
                ${colors.bold('helper tasks:')}
                    ${colors.primary('images')}            Build images. Convert src *.png images to *.webp and *.jpg. Crop & resize tasks
                    ${colors.primary('')}                      ${colors.gray('--imgActive=true')}

                ${colors.bold('options:')}
                    ${colors.yellow('--tag')}             Set build version.                  ${colors.gray(`default: --tag="${tagVersion}" -> dest path: public/js/${tagVersion}`)}
                    ${colors.yellow('--jsUglify')}        Set js uglification.                ${colors.gray('(true || false)')}
                    ${colors.yellow('--jsSourcemaps')}    Set js sourcemaps generation.       ${colors.gray('(true || false)')}
                    ${colors.yellow('--jsGzip')}          Set js "gzip" compression mode.     ${colors.gray('(true || false)')}
                    ${colors.yellow('--jsBrotli')}        Set js "brotli" compression mode.   ${colors.gray('(true || false)')}
                    ${colors.yellow('--cssSourcemaps')}   Set CSS sourcemaps generation.      ${colors.gray('(true || false)')}
                    ${colors.yellow('--cssGzip')}         Set CSS "gzip" compression mode.    ${colors.gray('(true || false)')}
                    ${colors.yellow('--cssBrotli')}       Set CSS "brotli" compression mode.  ${colors.gray('(true || false)')}
                    ${colors.yellow('--imgActive')}       Disables all image tasks.           ${colors.gray('(true || false)')}
                    ${colors.yellow('--debug')}           Set debug mode (more output).       ${colors.gray('(true || false)')}
            ${colors.divider(' ')}
    `);
};

/**
 * print JS summary table
 */
let printJsSummary = () => {
    let tableHead = trackTable.cols;
    let byteCols = [1,3,6,9,12];
    let percentCols = [2, 5, 8, 10];
    let sortCol = (CONF.JS.BROTLI || CONF.CSS.BROTLI || CONF.JS.GZIP || CONF.CSS.GZIP || CONF.JS.UGLIFY) ? 10 : CONF.JS.SOURCEMAPS ? 3 : 1;
    let refAllCol = (CONF.JS.BROTLI || CONF.CSS.BROTLI) ? 9 : (CONF.JS.GZIP || CONF.CSS.GZIP) ? 6 : CONF.JS.UGLIFY ? 3 : CONF.JS.SOURCEMAPS ? 3 : 1;
    let highLightSections = {src_percent: [], gzip_percent: [], brotli_percent: [], all_percent: []};
    let highLightRow = {
        success: JSON.parse(JSON.stringify(highLightSections)),
        warning: JSON.parse(JSON.stringify(highLightSections)),
        danger: JSON.parse(JSON.stringify(highLightSections))
    };

    let numFormatCols = byteCols.concat(percentCols);
    let sumRow = [];
    let widthCol = [35, 10, 8, 10, 35, 8, 10, 35, 8, 10, 8, 35, 10];

    let table = new Table({
        borderStyle: 2,
        horizontalLine: true,
        width: widthCol,
        rightPadding: 0,
        leftPadding: 0
    });

    // -- Table header --------------------------------------------------------
    table.push(tableHead.map(label => label.replace(/^(.*?)_(percent?).*/i, '$1 %')));

    // convert JSON to array
    let tableData = [];
    for (let fileName in trackedFiles) {
        let rowData = trackedFiles[fileName];
        rowData.unshift(fileName);
        tableData.push(rowData);
    }

    let tableHeight = tableData.length + 1;
    let tableWidth = tableHead.length;

    tableData.sort((a,b) => a[sortCol] - b[sortCol]);

    table.attr(0, sortCol, {
        color: 'cyan'
    });

    // -- Table body ----------------------------------------------------------
    let tmpMapping =  {byteCols: byteCols, percentCols: percentCols};

    // sum column data for footer
    let sumCols = (arr, mapping) => arr.map((x, rowIdx) =>
        x.map((y, i) => {
            if(mapping.byteCols.includes(i)) {
                sumRow[i] = (sumRow[i]) ? sumRow[i] + y : y;
            }
            return y;
        })
    );

    // format table cell data
    let formatCols = (arr, mapping) => arr.map((x, rowIdx) =>
        x.map((y, i) => {
            if(mapping.byteCols.includes(i)) {
                return prettyBytes(y);
            }else if(mapping.percentCols.includes(i)){
                // 0.0% diff is "success" in case of Uglify is disabled
                let isSuccess = (
                    y === 1 &&
                    !CONF.JS.UGLIFY &&
                    tableHead[i] === 'src_percent'
                );

                if(y < 0.3 || isSuccess) {
                    highLightRow.success[tableHead[i]].push(rowIdx);
                }else if(y < 0.5 ){
                    highLightRow.warning[tableHead[i]].push(rowIdx);
                }else{
                    highLightRow.danger[tableHead[i]].push(rowIdx);
                }

                return y ? (100 * (1 - y )).toFixed(1) + '%' : '';
            }else{
                return y;
            }
        })
    );

    tableData = sumCols(tableData, tmpMapping);

    // -- Table footer --------------------------------------------------------
    // percent cell src
    sumRow[2] = ((sumRow[3] / sumRow[1]));
    // percent cell gzip
    sumRow[5] = (((sumRow[6] || 0) / sumRow[3]));
    // percent cell brotli
    sumRow[8] = (((sumRow[9] || 0) / sumRow[3]));
    // percent cell all
    sumRow[10] = (((sumRow[refAllCol] || 0) / sumRow[1]));

    tableData.push(sumRow);
    tableData = formatCols(tableData, tmpMapping);

    // add rows
    for(let i = 0; i < tableData.length; i++){
        table.push(tableData[i]);
    }

    // -- Table format --------------------------------------------------------
    for(let i = 0; i < numFormatCols.length; i++){
        table.attrRange({row: [0], column: [numFormatCols[i], numFormatCols[i] + 1]}, {
            align: 'right',
            rightPadding: 1
        });
    }

    for (let highLight in highLightRow) {
        if (highLightRow.hasOwnProperty(highLight)){
            for (let highLightSection in highLightRow[highLight]) {
                for(let i = 0; i < highLightRow[highLight][highLightSection].length; i++){
                    let rowIdx = highLightRow[highLight][highLightSection][i];

                    let color = (highLight === 'success') ? 'green' : (highLight === 'warning') ? 'yellow' : 'red';
                    let colFrom = 0;
                    let colTo = 1;
                    switch(highLightSection){
                        case 'src_percent':
                            colTo = 1;
                            colTo = 4;
                            break;
                        case 'gzip_percent':
                            colFrom = 4;
                            colTo = 7;
                            break;
                        case 'brotli_percent':
                            colFrom = 7;
                            colTo = 10;
                            break;
                        case 'all_percent':
                            colFrom = 10;
                            colTo = 11;
                            break;
                    }
                    table.attrRange({row: [rowIdx + 1, rowIdx + 2], column: [colFrom, colTo]},{
                        color: color
                    });
                }
            }
        }
    }

    // -- Remove irrelevant columns -------------------------------------------

    if(!CONF.JS.SOURCEMAPS){
        table.removeColumn(12);
        table.removeColumn(11);

        if(!CONF.JS.BROTLI && !CONF.CSS.BROTLI && !CONF.JS.GZIP && !CONF.CSS.GZIP){
            table.removeColumn(10);

            table.removeColumn(9);
            table.removeColumn(8);
            table.removeColumn(7);

            table.removeColumn(6);
            table.removeColumn(5);
            table.removeColumn(4);

            if(!CONF.JS.UGLIFY){
                table.removeColumn(3);
                table.removeColumn(2);
            }
        }
    }

    console.log(table.toString());

    // reset tracked files for next run e.g. watch change
    trackedFiles = {};
};

// == clean up tasks ==================================================================================================

/**
 * clean temp JS dest dir
 */
gulp.task('task:cleanJsDestBuild', () => del([PATH.JS.DEST_BUILD]));

/**
 * clean JS dest dir
 */
gulp.task('task:cleanJsDest', () => del([PATH.JS.DEST + '/' + CONF.TAG]));

/**
 * clean CSS dest dir
 */
gulp.task('task:cleanCssDest', () => del([PATH.ASSETS.DEST + '/css/' + CONF.TAG]));

/**
 * clean IMG dest dir
 */
gulp.task('task:cleanImgDest', () => del([PATH.ASSETS.DEST + '/img/' + CONF.TAG]));

// == Dev tasks (code analyses) =======================================================================================
gulp.task('task:hintJS', async () => {
    let eslint = new ESLint();
    let results = await eslint.lintFiles([PATH.JS.SRC]);
    let formatter = await eslint.loadFormatter('stylish');
    let resultText = formatter.format(results);
    if(resultText){
        log(resultText);
    }
});

// == JS build tasks ==================================================================================================

/**
 * concat/build JS files by modules
 */
gulp.task('task:concatJS', () => {
    let modules = ['login', 'mappage', 'setup', 'admin', 'pnotify.loader', 'datatables.loader', 'summernote.loader'];
    let srcModules = ['./js/app/*(' + modules.join('|') + ').js'];

    return gulp.src(srcModules, {base: 'js'})

        .pipe(gulpif(CONF.JS.SOURCEMAPS, sourcemaps.init()))
        .pipe(requirejsOptimize(function(file){

            return {
                name: file.stem,
                baseUrl: 'js',
                mainConfigFile: './js/app.js',
                optimize: 'none',
                inlineText: false,
                removeCombined: true,
                preserveLicenseComments: false,     // required for sourcemaps
                findNestedDependencies: false,
                include: ['text'],
             // excludeShallow: ['app'],
             // excludeShallow: ['./js/app.js'],
             // exclude: ['app.js'],
             // path: {
             //     pp: './../js/app' // the main config file will not be build
             // },
                onModuleBundleComplete: function(data){
                    // collect all combined js files
                    combinedJsFiles = [...new Set(combinedJsFiles.concat(data.included))];
                }
            };
        }))
        .pipe(bytediff.start())
        .pipe(gulpif(CONF.JS.UGLIFY, terser(uglifyJsOptions)))
        .pipe(gulpif(CONF.JS.SOURCEMAPS, sourcemaps.write('.', {includeContent: false, sourceRoot: '/js'}))) // prod (minify)
        .pipe(bytediff.stop(data => {
            trackFile(data, {src: 'startSize', src_percent: 'percent', uglify: 'endSize'});
            return colors.green('Build concat file "' + data.fileName + '"');
        }))
        .pipe(gulp.dest(PATH.JS.DEST_BUILD));
});

/**
 * build standalone JS files
 */ 
gulp.task('task:diffJS', () => {
    return gulp.src(PATH.JS.SRC, {base: 'js', since: gulp.lastRun('task:diffJS')})
        .pipe(filter(file => {
            return combinedJsFiles.indexOf(file.path) < 0;
        }))
        .pipe(debug({title: 'Copy JS src: ', showFiles: false}))
        .pipe(bytediff.start())
        .pipe(gulpif(CONF.JS.SOURCEMAPS, sourcemaps.init()))
        .pipe(gulpif(CONF.JS.UGLIFY, terser(uglifyJsOptions)))
        .pipe(gulpif(CONF.JS.SOURCEMAPS, sourcemaps.write('.', {includeContent: false, sourceRoot: '/js'})))
        .pipe(bytediff.stop(data => {
            trackFile(data, {src: 'startSize', src_percent: 'percent', uglify: 'endSize'});
            return colors.iconSuccess(`build → ${colors.magenta(data.fileName)}`);
        }))
        .pipe(gulp.dest(PATH.JS.DEST_BUILD, {overwrite: false}));
});

/**
 * get assets filter options e.g. for gZip or Brotli assets
 * @param compression
 * @param fileExt
 * @returns {{fileFilter, srcModules: string[]}}
 */
let getAssetFilterOptions = (compression, fileExt) => {
    return {
        srcModules: [
            PATH.ASSETS.DEST +'/**/*.' + fileExt,
            '!' + PATH.ASSETS.DEST + '/js/' + CONF.TAG + '{,/**/*}'
        ],
        fileFilter: filter(file => CONF[fileExt.toUpperCase()][compression.toUpperCase()])
    };
};

/**
 * build gZip assets
 * @param config
 * @param taskName
 * @returns {*}
 */
let gzipAssets = (config, taskName) => {
    return gulp.src(config.srcModules, {base: 'public', since: gulp.lastRun(taskName)})
        .pipe(config.fileFilter)
        .pipe(debug({title: 'Gzip asses dest: ', showFiles: false}))
        .pipe(bytediff.start())
        .pipe(gzip(gZipOptions))
        .pipe(bytediff.stop(data => {
            trackFile(data, {gzipFile: 'fileName', gzip: 'endSize'});
            if(fileExtension(data.fileName) === gZipOptions.extension){
                return colors.green('Gzip generate "' + data.fileName + '"');
            }else{
                return colors.gray('Gzip skip "' + data.fileName + '". Size < ' + gZipOptions.threshold + ' (threehold)');
            }
        }))
        .pipe(gulp.dest(PATH.ASSETS.DEST));
};

/**
 * build Brotli assets
 * @param config
 * @param taskName
 * @returns {*}
 */
let brotliAssets = (config, taskName) => {
    return gulp.src(config.srcModules, {base: 'public', since: gulp.lastRun(taskName)})
        .pipe(config.fileFilter)
        .pipe(debug({title: 'Brotli asses dest: ', showFiles: false}))
        .pipe(bytediff.start())
        .pipe(brotli(brotliOptions))
        .pipe(bytediff.stop(data => {
            trackFile(data, {brotliFile: 'fileName', brotli: 'endSize'});
            if(fileExtension(data.fileName) ===  brotliOptions.extension){
                return colors.green('Brotli generate "' + data.fileName + '"');
            }else{
                return colors.gray('Brotli skip "' + data.fileName + '"');
            }
        }))
        .pipe(gulp.dest(PATH.ASSETS.DEST));
};

gulp.task('task:gzipJsAssets', () => {
    return gzipAssets(getAssetFilterOptions('gzip', 'js'), 'task:gzipJsAssets');
});

gulp.task('task:gzipCssAssets', () => {
    return gzipAssets(getAssetFilterOptions('gzip', 'css'), 'task:gzipCssAssets');
});

gulp.task('task:brotliJsAssets', () => {
    return brotliAssets(getAssetFilterOptions('brotli', 'js'), 'task:brotliJsAssets');
});

gulp.task('task:brotliCssAssets', () => {
    return brotliAssets(getAssetFilterOptions('brotli', 'css'), 'task:brotliCssAssets');
});

/**
 * rename "temp" build JS folder to final dest folder
 * (keep "old" build data as long as possible in case of build failure)
 */
gulp.task('task:renameJsDest', () => {
    let fileExt = ['js', 'map'].concat(compressionExt);
    return gulp.src( PATH.JS.DEST_BUILD + '/**/*.{' + fileExt.join(',') + '}', {base: PATH.JS.DEST_BUILD, since: gulp.lastRun('task:renameJsDest')})
        .pipe(debug({title: 'Rename JS dest: ', showFiles: false}))
        .pipe(gulp.dest(PATH.JS.DEST_BUILD + '/../' + CONF.TAG));
});

// == CSS build tasks =================================================================================================

/**
 * build CSS rom SASS files
 * -> 1. dart-sass (sass)
 *    2. autoprefixer
 */
gulp.task('task:sass', () => {
    return gulp.src(PATH.CSS.SRC)
        .pipe(gulpif(CONF.CSS.SOURCEMAPS, sourcemaps.init()))
        .pipe(sass(sassOptions).on('error', sass.logError))
        .pipe(bytediff.start())
        .pipe(bytediff.stop(data => {
            trackFile(data, {src: 'startSize', src_percent: 'percent', uglify: 'endSize'});
            return colors.iconSuccess(`build → ${colors.magenta(data.fileName)}`);
        }))
        .pipe(gulpif(CONF.CSS.SOURCEMAPS, sourcemaps.write('.', {includeContent: false, sourceRoot: '../../../sass'})))
        .pipe(gulp.dest(PATH.ASSETS.DEST + '/css/' + CONF.TAG))

        .pipe(filter(['!*.map']))
        .pipe(gulpif(CONF.CSS.SOURCEMAPS, sourcemaps.init({loadMaps: true})))
        .pipe(autoprefixer(autoprefixerOptions))
        .pipe(bytediff.start())
        .pipe(bytediff.stop(data => {
            trackFile(data, {src: 'startSize', src_percent: 'percent', uglify: 'endSize'});
            return colors.green('Autoprefix CSS file "' + data.fileName + '"');
        }))
        .pipe(gulpif(CONF.CSS.SOURCEMAPS, sourcemaps.write('.', {includeContent: false})))
        .pipe(gulp.dest(PATH.ASSETS.DEST + '/css/' + CONF.TAG));
});

/**
 * css-clean can be used to "optimize" generated CSS [optional]
 */
gulp.task('task:cleanCss', () => {
    return gulp.src('public/css/' + CONF.TAG + '/*.css')
        .pipe(gulpif(CONF.CSS.SOURCEMAPS, sourcemaps.init({loadMaps: true})))
        .pipe(cleanCSS(cleanCssOptions))
        .pipe(gulpif(CONF.CSS.SOURCEMAPS, sourcemaps.write('.', {includeContent: false})))
        .pipe(gulp.dest(PATH.ASSETS.DEST + '/css/' + CONF.TAG));
});

// == Image build tasks ===============================================================================================

/**
 * sharp-based replacement for gulp-imagemin + imagemin-webp (drops the imagemin/cwebp-bin tree)
 * @param options
 */
let sharpWebp = options => through2.obj((file, enc, cb) => {
    if(file.isNull() || !file.contents){
        cb(null, file);
        return;
    }

    sharp(file.contents).webp(options).toBuffer().then(buffer => {
        file.contents = buffer;
        cb(null, file);
    }).catch(err => cb(err));
});

let imgWebpHandler = (config, taskName) => {
    return gulp.src(config.src, {base: config.base, since: gulp.lastRun(taskName), encoding: false})
        .pipe(sharpWebp(config.options))
        .pipe(rename({extname: '.webp'}))
        .pipe(gulp.dest(PATH.ASSETS.DEST + '/img/' + CONF.TAG, {encoding: false}));
};

gulp.task('task:imgHeadToWEBP', () => {
    return imgWebpHandler({
        src: PATH.ASSETS.DEST + '/img/' + CONF.TAG + '/header/**/*.png',
        base: PATH.ASSETS.DEST + '/img/' + CONF.TAG,
        options: webpOptions
    }, 'task:imgHeadToWEBP');
});

gulp.task('task:imgGalleryToWEBP', () => {
    return imgWebpHandler({
        src: PATH.ASSETS.DEST + '/img/' + CONF.TAG + '/gallery/**/*.jpg',
        base: PATH.ASSETS.DEST + '/img/' + CONF.TAG,
        options: Object.assign({}, webpOptions, {quality: 90}) // unfortunately src jpg´s are already high compressed
    }, 'task:imgGalleryToWEBP');
});

/**
 * sharp-based replacement for the unmaintained gulp-image-resize (gm/GraphicsMagick).
 * Mirrors its options: crop (resize-then-center-crop to exact w/h), upscale, format, quality (0-1, gm-style).
 * @param options
 */
let sharpResize = options => through2.obj((file, enc, cb) => {
    if(file.isNull() || !file.contents){
        cb(null, file);
        return;
    }

    let img = sharp(file.contents);
    img.metadata().then(meta => {
        let width = options.width;
        let height = options.height;
        if(!height){
            height = options.crop ? meta.height : Math.ceil((width / meta.width) * meta.height);
        }
        if(!width){
            width = options.crop ? meta.width : Math.ceil((height / meta.height) * meta.width);
        }

        let pipeline = img.resize({
            width, height,
            fit: (options.crop || options.cover) ? 'cover' : 'inside',
            position: 'centre',
            withoutEnlargement: !options.upscale
        });

        if(options.format){
            let formatOptions = (options.quality && options.quality !== 1) ? {quality: Math.floor(options.quality * 100)} : {};
            pipeline = pipeline.toFormat(options.format, formatOptions);
        }

        return pipeline.toBuffer();
    }).then(buffer => {
        file.contents = buffer;
        cb(null, file);
    }).catch(err => cb(err));
});

let imgResizeHandler = (config, taskName) => {
    return gulp.src(config.src, {base: 'img', since: gulp.lastRun(taskName), encoding: false})
        .pipe(sharpResize(config.options))
        .pipe(gulpif(config.rename, rename(config.rename)))
        .pipe(gulp.dest(PATH.ASSETS.DEST + '/img/' + CONF.TAG, {encoding: false}));
};

let imgResizeHeaderTasks = [];
[480, 780, 1200, 1600, 3840].forEach(size => {
    ['png', 'jpg'].forEach(format => {
        let taskName = `task:imgHead${size}${format}`;
        gulp.task(taskName, () => {
            return imgResizeHandler({
                src: PATH.IMG.SRC_HEADER,
                options: Object.assign({}, imgResizeOptions, {format: format, width: size}),
                rename: {suffix: `-${size}`}
            }, taskName);
        });
        imgResizeHeaderTasks.push(taskName);
    });
});

gulp.task('task:imgHeadResize', gulp.series(
    gulp.parallel(...imgResizeHeaderTasks),
    'task:imgHeadToWEBP'
));

gulp.task('task:imgGalleryCopy', () => {
    return gulp.src(PATH.IMG.SRC_GALLERY, {base: 'img', since: gulp.lastRun('task:imgGalleryCopy'), encoding: false})
        .pipe(gulp.dest(PATH.ASSETS.DEST + '/img/' + CONF.TAG, {encoding: false}));
});

gulp.task('task:imgGallery', gulp.series(
    'task:imgGalleryCopy',
    'task:imgGalleryToWEBP'
));

gulp.task('task:imgRest', () => {
    return gulp.src([
        PATH.IMG.SRC,
        `!${PATH.IMG.SRC_HEADER}`,
        `!${PATH.IMG.SRC_GALLERY}`,
        `!${PATH.IMG.SRC_SVG}`
    ], {base: 'img', since: gulp.lastRun('task:imgRest'), encoding: false})
        .pipe(debug({title: 'Copy img "rest" dest: ', showFiles: false}))
        .pipe(gulp.dest(PATH.ASSETS.DEST + '/img/' + CONF.TAG, {encoding: false}));
});

gulp.task('task:imgSVG', () => {
    return gulp.src(PATH.IMG.SRC_SVG, {base: 'img', since: gulp.lastRun('task:imgSVG'), encoding: false})
        .pipe(debug({title: 'Copy img SVG dest: ', showFiles: false}))
        .pipe(gulp.dest(PATH.ASSETS.DEST + '/img/' + CONF.TAG, {encoding: false}));
});

// == Helper tasks ====================================================================================================

/**
 * print Gulp help information
 */
gulp.task('task:printHelp', done => {
    printHelp();
    done();
});

/**
 * print JS build task summary as table (e.g. show saved file size)
 */
gulp.task('task:printJsSummary', done => {
    printJsSummary();
    done();
});

/**
 * print task configuration (e.g. CLI parameters)
 */
gulp.task('task:printConfig', done => {
    let error = colors.danger;
    let columnLength = Math.round(cliBoxLength / 2);

    log(colors.divider('config'));

    let configFlat = flatten(CONF);
    for (let key in configFlat) {
        if (configFlat.hasOwnProperty(key)){
            let value = configFlat[key];
            let success = colors.success;
            switch(key){
                case 'TASK': success = colors.primary; break;
            }
            // format value
            value = padEnd((typeof value === 'undefined') ? 'undefined': value, columnLength);
            log(
                colors.yellow(padEnd(key, columnLength)),
                configFlat[key] ? success(value) : error(value)
            );
        }
    }
    log(colors.divider(' '));
    done();
});

/**
 * check CLI parameters and task config
 */
gulp.task('task:checkConfig', done => {
    if(!CONF.TAG){
        printError(`Missing TAG version. Add param: ${colors.yellow('--tag')}`, `$ npm run gulp default -- --tag="${tagVersion}"`);
        process.exit(0);
    }
    done();
});

/**
 * configure "develop" task
 */
gulp.task('task:configDevelop',
    gulp.series(
        done => {
            let CONF_DEVELOP = {
                JS: {
                    UGLIFY: false,
                    SOURCEMAPS: false,
                    GZIP: false,
                    BROTLI: false
                },
                CSS: {
                    SOURCEMAPS: true,
                    GZIP: false,
                    BROTLI: false
                },
                IMG: {
                    ACTIVE: true
                }
            };

            CONF = mergeConf(CONF, CONF_DEVELOP);
            done();
        },
        'task:printConfig',
        'task:checkConfig'
    )
);
/**
 * configure "production" task
 */
gulp.task('task:configProduction',
    gulp.series(
        done => {
            let CONF_PRODUCTION = {
                JS: {
                    UGLIFY: true,
                    SOURCEMAPS: true,
                    GZIP: true,
                    BROTLI: true
                },
                CSS: {
                    SOURCEMAPS: true,
                    GZIP: true,
                    BROTLI: true
                },
                IMG: {
                    ACTIVE: true
                }
            };

            CONF = mergeConf(CONF, CONF_PRODUCTION);
            done();
        },
        'task:printConfig',
        'task:checkConfig'
    )
);

/**
 * configure "image" task (standalone)
 */
gulp.task('task:configImages',
    gulp.series(
        done => {
            let CONF_IMAGES = {
                IMG: {
                    ACTIVE: true
                }
            };

            CONF = mergeConf(['TASK', 'TAG', 'IMG'].reduce((obj, key) => ({ ...obj, [key]: CONF[key] }), {}), CONF_IMAGES);
            done();
        },
        'task:printConfig',
        'task:checkConfig'
    )
);

/**
 * updates JS destination move to (final) dir
 */
gulp.task('task:updateJsDest', gulp.series(
    gulp.parallel(
        'task:gzipJsAssets',
        'task:brotliJsAssets'
    ),
    'task:renameJsDest',
    // 'task:printJsSummary',
    'task:cleanJsDestBuild'
));

/**
 * build JS dest files (concat, uglify, sourcemaps)
 */
gulp.task('task:buildJs', gulp.series(
    'task:concatJS',
    'task:diffJS',
    'task:cleanJsDest',
    'task:updateJsDest'
));

/**
 * build SCSS dest files
 */
gulp.task('task:buildCss', gulp.series(
    'task:sass'
));

/**
 * build IMG dest files
 */
gulp.task('task:buildImg', gulp.parallel(
    'task:imgHeadResize',
    'task:imgGallery',
    'task:imgRest',
    'task:imgSVG'
));

// == Notification tasks ==============================================================================================

/**
 * JS Build done notification
 */
gulp.task('task:notifyJsDone', done => {
    notifier.notify({
        title: 'Done JS build',
        message: 'JS build task finished',
        icon: PATH.ASSETS.DEST + '/img/logo.png',
        wait: false
    });
    done();
});

/**
 * CSS Build done notification
 */
gulp.task('task:notifyCssDone', done => {
    notifier.notify({
        title: 'Done CSS build',
        message: 'CSS build task finished',
        icon: PATH.ASSETS.DEST + '/img/logo.png',
        wait: false
    });
    done();
});

// == Watcher tasks ===================================================================================================

/**
 * task for JS src file changes
 */
gulp.task(
    'task:watchJsSrc',
    gulp.series(
        'task:hintJS',
        'task:diffJS',
        'task:updateJsDest',
        'task:notifyJsDone'
    )
);

/**
 * task for JS src file changes
 */
gulp.task(
    'task:watchCss',
    gulp.series(
        'task:buildCss',
        // 'task:cleanCss',
        gulp.parallel(
            'task:gzipCssAssets',
            'task:brotliCssAssets'
        ),
        // 'task:printJsSummary',
        'task:notifyCssDone'
    )
);

gulp.task(
    'task:watchImg',
    gulp.series(
        'task:buildImg'
    )
);

/**
 * watch files for changes
 */

gulp.task('task:setWatcherJs',  () => gulp.watch(PATH.JS.SRC, gulp.series('task:watchJsSrc', 'task:printJsSummary')));
gulp.task('task:setWatcherCss', () => gulp.watch(PATH.CSS.SRC, gulp.series('task:watchCss', 'task:printJsSummary')));
gulp.task('task:setWatcherImg', () => gulp.watch(PATH.IMG.SRC, gulp.series('task:watchImg', 'task:printJsSummary')));

gulp.task('task:setWatcher',
    gulp.parallel(
        'task:setWatcherJs',
        'task:setWatcherCss',
        'task:setWatcherImg'
    )
);

// == Default/Main tasks ==============================================================================================

gulp.task(
    'help',
    gulp.series(
        'task:printHelp'
    )
);

gulp.task(
    'default',
    gulp.series(
        'task:configDevelop',
        gulp.parallel(
            gulp.series(
                'task:cleanJsDestBuild',
                'task:watchJsSrc'
            ),
            gulp.series(
                'task:cleanCssDest',
                'task:watchCss'
            ),
            gulp.series(
                'task:cleanImgDest',
                'task:watchImg'
            )
        ),
        'task:printJsSummary',
        'task:setWatcher'
    )
);

gulp.task(
    'production',
    gulp.series(
        'task:configProduction',
        gulp.parallel(
            gulp.series(
                'task:cleanJsDestBuild',
                'task:buildJs'
            ),
            gulp.series(
                'task:cleanCssDest',
                'task:watchCss'
            ),
            gulp.series(
                'task:cleanImgDest',
                'task:watchImg'
            )
        ),
        'task:printJsSummary'
    )
);

gulp.task(
    'images',
    gulp.series(
        'task:configImages',
        'task:cleanImgDest',
        'task:watchImg',
        //'task:printJsSummary'
    )
);

