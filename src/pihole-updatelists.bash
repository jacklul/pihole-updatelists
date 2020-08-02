#/usr/bin/env bash
# https://unix.stackexchange.com/a/55622

_pihole_updatelists()
{
    local cur prev opts
	
    COMPREPLY=()
    cur="${COMP_WORDS[COMP_CWORD]}"
    prev="${COMP_WORDS[COMP_CWORD-1]}"
    opts="--config= --git-branch= --help --no-gravity --no-vacuum --verbose --debug --update --version"

    case "${prev}" in
            "--help")
                opts="--update --version"
            ;;
            "--version"|"--update")
                opts="--git-branch="
            ;;
    esac

    if [[ ${prev} == "--"* && ${cur} == "=" ]] ; then
        compopt -o filenames
        COMPREPLY=(*)

        return 0
    fi

    if [[ ${prev} == '=' ]] ; then
        cur=${cur//\\ / }
        [[ ${cur} == "~/"* ]] && cur=${cur/\~/$HOME}
        compopt -o filenames
        local files=("${cur}"*)
        [[ -e ${files[0]} ]] && COMPREPLY=( "${files[@]// /\ }" )

        return 0
    fi

    COMPREPLY=( $(compgen -W "${opts}" -- "${cur}") )

    if [[ ${#COMPREPLY[@]} == 1 && ${COMPREPLY[0]} != "--"*"=" ]] ; then
        compopt +o nospace
    fi
	
    return 0
}

complete -o nospace -F _pihole_updatelists pihole-updatelists
